<?php

namespace Tests\Feature;

use App\Exceptions\PlatformException;
use App\Models\CompanyEntitlement;
use App\Models\OutreachEnrollment;
use App\Models\OutreachMessage;
use App\Models\OutreachMessageEvent;
use App\Services\Outreach\OutreachSchedulingService;
use App\Services\Outreach\SequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutreachReportingComplianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporting_uses_integer_basis_points_and_breakdowns(): void
    {
        $context = $this->stage11OutreachContext();
        $messageA = $this->message($context, 'SENT', true, true);
        $this->message($context, 'FAILED', false, false);
        OutreachMessageEvent::factory()->for($context['company'])->for($messageA, 'message')->create(['provider_event_id' => 'open-report', 'type' => 'OPENED']);
        OutreachMessageEvent::factory()->for($context['company'])->for($messageA, 'message')->create(['provider_event_id' => 'reply-report', 'type' => 'REPLIED']);

        $this->getJson('/api/v1/outreach/reports', ['X-Company-Id' => $context['company']->id])->assertOk()->assertJsonPath('data.sent', 1)->assertJsonPath('data.delivered', 1)->assertJsonPath('data.delivery_rate_bps', 10000)->assertJsonPath('data.open_rate_bps', 10000)->assertJsonPath('data.reply_rate_bps', 10000);
    }

    public function test_scheduling_respects_company_timezone_weekdays_and_window(): void
    {
        $context = $this->stage11OutreachContext();
        $sequence = $context['sequence'];
        $sequence->update(['timezone' => 'Asia/Karachi', 'allowed_weekdays' => [1, 2, 3, 4, 5], 'send_window_start' => '09:00', 'send_window_end' => '17:00']);

        $scheduled = app(OutreachSchedulingService::class)->nextEligible($sequence->fresh(), CarbonImmutable::parse('2026-09-26 08:00:00', 'Asia/Karachi'));

        $this->assertSame('2026-09-28 04:00:00', $scheduled->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $scheduled->timezoneName);
    }

    public function test_scheduling_honors_sequence_start_date_before_applying_window(): void
    {
        $context = $this->stage11OutreachContext();
        $context['sequence']->update(['timezone' => 'Asia/Karachi', 'starts_at' => '2026-09-29 06:30:00', 'allowed_weekdays' => [1, 2, 3, 4, 5], 'send_window_start' => '09:00', 'send_window_end' => '17:00']);

        $scheduled = app(OutreachSchedulingService::class)->nextEligible($context['sequence']->fresh(), CarbonImmutable::parse('2026-09-25 00:00:00', 'UTC'));

        $this->assertSame('2026-09-29 06:30:00', $scheduled->format('Y-m-d H:i:s'));
    }

    public function test_active_sequence_limit_is_enforced_server_side(): void
    {
        $context = $this->stage11OutreachContext();
        CompanyEntitlement::query()->where('company_id', $context['company']->id)->update(['limits' => ['active_sequences' => 0, 'sending_identities' => 5, 'enrolled_recipients' => 100, 'daily_messages' => 100]]);

        try {
            app(SequenceService::class)->transition($context['sequence'], 'ACTIVE', $context['company']->id, $context['user']->id);
            $this->fail('Sequence limit should block activation.');
        } catch (PlatformException $exception) {
            $this->assertSame('SUBSCRIPTION_LIMIT_REACHED', $exception->errorCode);
        }
    }

    public function test_missing_physical_address_blocks_enrollment_before_message_creation(): void
    {
        $context = $this->stage11OutreachContext();
        app(SequenceService::class)->transition($context['sequence'], 'ACTIVE', $context['company']->id, $context['user']->id);
        $context['company']->settings()->update(['outreach_physical_address' => null]);

        $response = $this->postJson('/api/v1/outreach/sequences/'.$context['sequence']->id.'/enrollments', ['recipient_type' => 'CONTACT', 'recipient_ids' => [$context['contact']->id], 'idempotency_key' => 'missing-address'], ['X-Company-Id' => $context['company']->id])->assertCreated();

        $this->assertSame('COMPLIANCE_SETTINGS_REQUIRED', $response->json('failures.0.error_code'));
        $this->assertSame(0, OutreachMessage::count());
    }

    public function test_tracking_and_unsubscribe_are_present_in_snapshot(): void
    {
        $context = $this->stage11OutreachContext();
        $context['template']->update(['body_html' => '<p>Hello {{contact.first_name}}</p><a href="https://example.test/demo">Demo</a>']);
        app(SequenceService::class)->transition($context['sequence'], 'ACTIVE', $context['company']->id, $context['user']->id);
        $this->postJson('/api/v1/outreach/sequences/'.$context['sequence']->id.'/enrollments', ['recipient_type' => 'CONTACT', 'recipient_ids' => [$context['contact']->id], 'idempotency_key' => 'tracking'], ['X-Company-Id' => $context['company']->id])->assertCreated();
        $message = OutreachMessage::query()->firstOrFail();

        $this->assertStringContainsString('/api/v1/outreach/unsubscribe/', $message->body_text);
        $this->assertStringContainsString('/api/v1/outreach/open/', (string) $message->body_html);
        $this->assertStringContainsString('/api/v1/outreach/click/', (string) $message->body_html);
        $this->assertSame(1, $message->links()->count());
    }

    /** @param array<string, mixed> $context */
    private function message(array $context, string $state, bool $sent, bool $delivered): OutreachMessage
    {
        $enrollment = OutreachEnrollment::factory()->for($context['company'])->for($context['sequence'], 'sequence')->create(['recipient_id' => fake()->unique()->uuid(), 'created_by' => $context['user']->id]);

        return OutreachMessage::factory()->for($context['company'])->for($enrollment, 'enrollment')->for($context['sequence'], 'sequence')->for($context['identity'], 'sendingIdentity')->create(['sequence_step_id' => $context['sequence']->steps()->firstOrFail()->id, 'state' => $state, 'sent_at' => $sent ? now() : null, 'delivered_at' => $delivered ? now() : null]);
    }
}
