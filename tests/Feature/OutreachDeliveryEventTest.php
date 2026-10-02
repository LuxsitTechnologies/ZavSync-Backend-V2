<?php

namespace Tests\Feature;

use App\Contracts\OutboundEmailGateway;
use App\Exceptions\OutreachException;
use App\Exceptions\PlatformException;
use App\Jobs\ProcessDueOutreach;
use App\Jobs\SendOutreachMessage;
use App\Models\CompanyEntitlement;
use App\Models\CrmActivity;
use App\Models\OutreachMessage;
use App\Models\OutreachMessageEvent;
use App\Models\OutreachMessageLink;
use App\Models\OutreachSendAttempt;
use App\Models\OutreachSuppression;
use App\Models\SecurityEvent;
use App\Services\Outreach\MessageDeliveryService;
use App\Services\Outreach\OutreachEventService;
use App\Services\Outreach\ProviderSendResult;
use App\Services\Outreach\SequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\MariaDbCertification;
use Tests\TestCase;

class OutreachDeliveryEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MariaDbCertification::protectExternalProviders($this->app);
    }

    public function test_due_message_is_queued_and_delivery_is_idempotent_with_crm_activity(): void
    {
        Queue::fake([SendOutreachMessage::class]);
        $context = $this->messageContext();
        $context['message']->update(['scheduled_at' => now()->subMinute()]);

        (new ProcessDueOutreach)->handle();
        (new ProcessDueOutreach)->handle();

        Queue::assertPushed(SendOutreachMessage::class, 1);
        $gateway = $this->mock(OutboundEmailGateway::class);
        $gateway->shouldReceive('send')->once()->andReturn(new ProviderSendResult('provider-123'));
        app(MessageDeliveryService::class)->deliver($context['message']->id);
        app(MessageDeliveryService::class)->deliver($context['message']->id);

        $this->assertDatabaseHas('outreach_messages', ['id' => $context['message']->id, 'state' => 'SENT', 'provider_message_id' => 'provider-123']);
        $this->assertSame(1, OutreachSendAttempt::count());
        $this->assertSame(1, CrmActivity::query()->where('outcome', 'outreach_message:'.$context['message']->id)->count());
    }

    public function test_provider_failure_records_attempt_without_duplicate_message(): void
    {
        $context = $this->messageContext();
        $context['message']->update(['scheduled_at' => now()->subMinute()]);
        $gateway = $this->mock(OutboundEmailGateway::class);
        $gateway->shouldReceive('send')->once()->andThrow(new RuntimeException('network secret detail'));

        try {
            app(MessageDeliveryService::class)->deliver($context['message']->id);
            $this->fail('Provider failure must be propagated for queue retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Temporary provider delivery failure.', $exception->getMessage());
        }

        $this->assertDatabaseHas('outreach_messages', ['id' => $context['message']->id, 'state' => 'FAILED', 'failure_message' => 'Provider delivery failed.']);
        $this->assertDatabaseHas('outreach_send_attempts', ['message_id' => $context['message']->id, 'status' => 'FAILED']);
        $this->assertDatabaseMissing('outreach_send_attempts', ['error_message' => 'network secret detail']);
        $this->assertSame(1, OutreachMessage::count());
    }

    public function test_permanent_provider_failure_is_not_thrown_for_automatic_retry(): void
    {
        $context = $this->messageContext();
        $context['message']->update(['scheduled_at' => now()->subMinute()]);
        $gateway = $this->mock(OutboundEmailGateway::class);
        $gateway->shouldReceive('send')->once()->andThrow(new OutreachException('PROVIDER_NOT_CONFIGURED', 'Provider unavailable.', 409));

        $result = app(MessageDeliveryService::class)->deliver($context['message']->id);

        $this->assertSame('FAILED', $result->state);
        $this->assertDatabaseHas('outreach_send_attempts', ['message_id' => $context['message']->id, 'status' => 'FAILED', 'error_code' => 'PROVIDER_NOT_CONFIGURED']);
    }

    public function test_ambiguous_started_attempt_is_not_sent_again_automatically(): void
    {
        $context = $this->messageContext();
        $context['message']->update(['state' => 'SENDING', 'scheduled_at' => now()->subMinute()]);
        OutreachSendAttempt::factory()->for($context['company'])->for($context['message'], 'message')->create(['attempt_number' => 1, 'status' => 'STARTED', 'completed_at' => null]);
        $gateway = $this->mock(OutboundEmailGateway::class);
        $gateway->shouldNotReceive('send');

        $result = app(MessageDeliveryService::class)->deliver($context['message']->id);

        $this->assertSame('FAILED', $result->state);
        $this->assertSame('DELIVERY_OUTCOME_UNKNOWN', $context['message']->attempts()->firstOrFail()->error_code);
    }

    public function test_paused_sequence_does_not_queue_or_send_due_messages(): void
    {
        Queue::fake([SendOutreachMessage::class]);
        $context = $this->messageContext();
        $context['message']->update(['scheduled_at' => now()->subMinute()]);
        app(SequenceService::class)->transition($context['sequence']->fresh(), 'PAUSED', $context['company']->id, $context['user']->id);

        (new ProcessDueOutreach)->handle();
        Queue::assertNotPushed(SendOutreachMessage::class);
        $this->assertSame('SCHEDULED', $context['message']->fresh()->state);
    }

    public function test_daily_message_limit_is_enforced_before_provider_call(): void
    {
        $context = $this->messageContext();
        $context['message']->update(['scheduled_at' => now()->subMinute()]);
        CompanyEntitlement::query()->where('company_id', $context['company']->id)->update(['limits' => ['active_sequences' => 10, 'sending_identities' => 5, 'enrolled_recipients' => 1000, 'daily_messages' => 0]]);
        $gateway = $this->mock(OutboundEmailGateway::class);
        $gateway->shouldNotReceive('send');

        try {
            app(MessageDeliveryService::class)->deliver($context['message']->id);
            $this->fail('The daily sending limit must be enforced.');
        } catch (PlatformException $exception) {
            $this->assertSame('SUBSCRIPTION_LIMIT_REACHED', $exception->errorCode);
        }

        $this->assertSame(0, OutreachSendAttempt::count());
    }

    public function test_suppression_is_rechecked_immediately_before_send(): void
    {
        $context = $this->messageContext();
        $context['message']->update(['scheduled_at' => now()->subMinute()]);
        OutreachSuppression::factory()->for($context['company'])->create(['email' => $context['message']->to_email, 'normalized_email' => mb_strtolower($context['message']->to_email), 'created_by' => $context['user']->id]);
        $gateway = $this->mock(OutboundEmailGateway::class);
        $gateway->shouldNotReceive('send');

        app(MessageDeliveryService::class)->deliver($context['message']->id);

        $this->assertDatabaseHas('outreach_messages', ['id' => $context['message']->id, 'state' => 'SUPPRESSED']);
        $this->assertDatabaseHas('outreach_enrollments', ['id' => $context['message']->enrollment_id, 'status' => 'SUPPRESSED']);
    }

    public function test_delivery_events_are_idempotent_and_bounce_suppresses_recipient(): void
    {
        $context = $this->messageContext();
        $context['message']->update(['provider_message_id' => 'provider-bounce', 'state' => 'SENT', 'sent_at' => now()]);
        $event = ['provider_event_id' => 'event-1', 'provider_message_id' => 'provider-bounce', 'type' => 'HARD_BOUNCE', 'occurred_at' => now()->toIso8601String(), 'payload' => ['reason' => 'invalid mailbox']];

        app(OutreachEventService::class)->process($context['company']->id, $event);
        app(OutreachEventService::class)->process($context['company']->id, $event);

        $this->assertSame(1, OutreachMessageEvent::count());
        $this->assertDatabaseHas('outreach_messages', ['id' => $context['message']->id, 'state' => 'BOUNCED']);
        $this->assertDatabaseHas('outreach_suppressions', ['company_id' => $context['company']->id, 'normalized_email' => mb_strtolower($context['message']->to_email), 'reason' => 'HARD_BOUNCE']);
    }

    public function test_reply_stops_sequence_when_configured(): void
    {
        $context = $this->messageContext();
        $context['message']->update(['provider_message_id' => 'provider-reply', 'state' => 'DELIVERED', 'sent_at' => now(), 'delivered_at' => now()]);

        app(OutreachEventService::class)->process($context['company']->id, ['provider_event_id' => 'reply-1', 'provider_message_id' => 'provider-reply', 'type' => 'REPLIED', 'occurred_at' => now()->toIso8601String(), 'payload' => []]);

        $this->assertDatabaseHas('outreach_enrollments', ['id' => $context['message']->enrollment_id, 'status' => 'REPLIED']);
        $this->assertNotNull($context['message']->fresh()->replied_at);
        $this->assertSame(1, CrmActivity::query()->where('outcome', 'like', 'outreach_event:%')->count());
    }

    public function test_open_click_and_unsubscribe_tokens_are_secure_and_idempotent(): void
    {
        $context = $this->messageContext();
        $openToken = 'known-open-token';
        $unsubscribeToken = 'known-unsubscribe-token';
        $clickToken = 'known-click-token';
        $context['message']->update(['tracking_token_hash' => hash('sha256', $openToken), 'unsubscribe_token_hash' => hash('sha256', $unsubscribeToken)]);
        OutreachMessageLink::factory()->for($context['company'])->for($context['message'], 'message')->create(['token_hash' => hash('sha256', $clickToken), 'destination_url' => 'https://example.test/pricing']);

        $this->get('/api/v1/outreach/open/'.$context['message']->id.'/'.$openToken)->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->get('/api/v1/outreach/open/'.$context['message']->id.'/'.$openToken)->assertOk();
        $this->get('/api/v1/outreach/click/'.$clickToken.'?redirect=https://evil.test')->assertRedirect('https://example.test/pricing');
        $this->get('/api/v1/outreach/open/'.$context['message']->id.'/tampered')->assertNotFound();
        $this->post('/api/v1/outreach/unsubscribe/'.$openToken)->assertNotFound();
        $this->post('/api/v1/outreach/unsubscribe/'.$unsubscribeToken)->assertOk();
        $this->post('/api/v1/outreach/unsubscribe/'.$unsubscribeToken)->assertOk();

        $this->assertSame(1, OutreachMessageEvent::query()->where('type', 'OPENED')->count());
        $this->assertSame(1, OutreachMessageEvent::query()->where('type', 'UNSUBSCRIBED')->count());
        $this->assertSame(1, OutreachSuppression::count());
    }

    public function test_forged_webhook_is_rejected_and_recorded_without_payload_or_secret_leakage(): void
    {
        $context = $this->stage11OutreachContext();
        $gateway = $this->mock(OutboundEmailGateway::class);
        $gateway->shouldReceive('webhookIsAuthentic')->once()->andReturnFalse();

        $this->postJson('/api/v1/outreach/providers/'.$context['connection']->id.'/webhook', ['secret' => 'must-not-be-logged'])->assertUnauthorized()->assertJsonPath('error_code', 'INVALID_WEBHOOK_SIGNATURE');

        $event = SecurityEvent::query()->where('type', 'OUTREACH_WEBHOOK_REJECTED')->firstOrFail();
        $this->assertSame($context['company']->id, $event->company_id);
        $this->assertStringNotContainsString('must-not-be-logged', json_encode($event->metadata, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function messageContext(): array
    {
        $context = $this->stage11OutreachContext();
        app(SequenceService::class)->transition($context['sequence'], 'ACTIVE', $context['company']->id, $context['user']->id);
        $this->postJson('/api/v1/outreach/sequences/'.$context['sequence']->id.'/enrollments', ['recipient_type' => 'CONTACT', 'recipient_ids' => [$context['contact']->id], 'idempotency_key' => 'message-context'], ['X-Company-Id' => $context['company']->id])->assertCreated();
        $context['message'] = OutreachMessage::query()->firstOrFail();

        return $context;
    }
}
