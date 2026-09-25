<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\CrmContact;
use App\Models\OutreachEnrollment;
use App\Models\OutreachMessage;
use App\Models\OutreachSuppression;
use App\Services\Outreach\SequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutreachSequenceEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequence_can_be_created_activated_paused_and_resumed(): void
    {
        $context = $this->stage11OutreachContext();
        $created = $this->postJson('/api/v1/outreach/sequences', $this->sequencePayload($context), $this->headers($context['company']->id))->assertCreated()->assertJsonPath('status', 'DRAFT')->assertJsonCount(3, 'steps');
        $url = '/api/v1/outreach/sequences/'.$created->json('id').'/transition';

        $this->postJson($url, ['status' => 'ACTIVE'], $this->headers($context['company']->id))->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->postJson($url, ['status' => 'PAUSED'], $this->headers($context['company']->id))->assertOk()->assertJsonPath('status', 'PAUSED');
        $this->postJson($url, ['status' => 'ACTIVE'], $this->headers($context['company']->id))->assertOk()->assertJsonPath('status', 'ACTIVE');
    }

    public function test_active_sequence_structure_is_immutable(): void
    {
        $context = $this->stage11OutreachContext();
        app(SequenceService::class)->transition($context['sequence'], 'ACTIVE', $context['company']->id, $context['user']->id);

        $this->putJson('/api/v1/outreach/sequences/'.$context['sequence']->id, $this->sequencePayload($context), $this->headers($context['company']->id))->assertConflict()->assertJsonPath('error_code', 'SEQUENCE_STRUCTURE_IMMUTABLE');
    }

    public function test_verified_sender_is_required_for_activation(): void
    {
        $context = $this->stage11OutreachContext();
        $context['identity']->update(['verification_status' => 'PENDING', 'verified_at' => null]);

        $this->postJson('/api/v1/outreach/sequences/'.$context['sequence']->id.'/transition', ['status' => 'ACTIVE'], $this->headers($context['company']->id))->assertConflict()->assertJsonPath('error_code', 'VERIFIED_SENDER_REQUIRED');
    }

    public function test_contact_enrollment_creates_immutable_scheduled_snapshot_and_is_idempotent(): void
    {
        $context = $this->activeContext();
        $payload = ['recipient_type' => 'CONTACT', 'recipient_ids' => [$context['contact']->id], 'idempotency_key' => 'enroll-contact'];

        $first = $this->postJson('/api/v1/outreach/sequences/'.$context['sequence']->id.'/enrollments', $payload, $this->headers($context['company']->id))->assertCreated()->assertJsonCount(1, 'enrolled')->assertJsonCount(0, 'failures');
        $second = $this->postJson('/api/v1/outreach/sequences/'.$context['sequence']->id.'/enrollments', $payload, $this->headers($context['company']->id))->assertCreated()->assertJsonCount(1, 'enrolled');
        $message = OutreachMessage::query()->firstOrFail();

        $this->assertSame(1, OutreachEnrollment::count());
        $this->assertSame(1, OutreachMessage::count());
        $this->assertSame('Ayesha Khan', $message->to_name);
        $this->assertStringContainsString('Ayesha', $message->subject);
        $this->assertSame($first->json('enrolled.0.id'), $second->json('enrolled.0.id'));
    }

    public function test_suppressed_and_invalid_recipients_return_per_recipient_failures(): void
    {
        $context = $this->activeContext();
        OutreachSuppression::factory()->for($context['company'])->create(['email' => $context['contact']->email, 'normalized_email' => mb_strtolower($context['contact']->email), 'created_by' => $context['user']->id]);
        $context['lead']->update(['email' => null]);

        $contact = $this->postJson('/api/v1/outreach/sequences/'.$context['sequence']->id.'/enrollments', ['recipient_type' => 'CONTACT', 'recipient_ids' => [$context['contact']->id], 'idempotency_key' => 'suppressed'], $this->headers($context['company']->id))->assertCreated();
        $lead = $this->postJson('/api/v1/outreach/sequences/'.$context['sequence']->id.'/enrollments', ['recipient_type' => 'LEAD', 'recipient_ids' => [$context['lead']->id], 'idempotency_key' => 'invalid'], $this->headers($context['company']->id))->assertCreated();

        $this->assertSame('RECIPIENT_SUPPRESSED', $contact->json('failures.0.error_code'));
        $this->assertSame('RECIPIENT_EMAIL_INVALID', $lead->json('failures.0.error_code'));
        $this->assertSame(0, OutreachEnrollment::count());
    }

    public function test_owner_score_and_source_filters_select_authoritative_leads(): void
    {
        $context = $this->activeContext();
        $context['lead']->update(['score' => 90, 'source' => 'REFERRAL']);

        $this->postJson('/api/v1/outreach/sequences/'.$context['sequence']->id.'/enrollments', ['recipient_type' => 'LEAD', 'filters' => ['owner_id' => $context['user']->id, 'source' => 'REFERRAL', 'min_score' => 80], 'idempotency_key' => 'filtered-leads'], $this->headers($context['company']->id))->assertCreated()->assertJsonCount(1, 'enrolled');
    }

    public function test_bulk_enrollment_reports_foreign_and_duplicate_recipients_without_silent_loss(): void
    {
        $context = $this->activeContext();
        $second = CrmContact::factory()->for($context['company'])->for($context['account'], 'account')->create(['status' => 'ACTIVE', 'created_by' => $context['user']->id]);
        $foreign = CrmContact::factory()->for(Company::factory())->create();
        $url = '/api/v1/outreach/sequences/'.$context['sequence']->id.'/enrollments';

        $this->postJson($url, ['recipient_type' => 'CONTACT', 'recipient_ids' => [$context['contact']->id, $second->id, $foreign->id], 'idempotency_key' => 'bulk'], $this->headers($context['company']->id))->assertCreated()->assertJsonCount(2, 'enrolled')->assertJsonPath('failures.0.error_code', 'RECIPIENT_NOT_FOUND');
        $this->postJson($url, ['recipient_type' => 'CONTACT', 'recipient_ids' => [$context['contact']->id], 'idempotency_key' => 'different-request'], $this->headers($context['company']->id))->assertCreated()->assertJsonPath('failures.0.error_code', 'RECIPIENT_ALREADY_ENROLLED');

        $this->assertSame(2, OutreachEnrollment::count());
    }

    public function test_sender_is_rechecked_before_enrollment(): void
    {
        $context = $this->activeContext();
        $context['connection']->update(['status' => 'DISCONNECTED']);

        $this->postJson('/api/v1/outreach/sequences/'.$context['sequence']->id.'/enrollments', ['recipient_type' => 'CONTACT', 'recipient_ids' => [$context['contact']->id], 'idempotency_key' => 'sender-recheck'], $this->headers($context['company']->id))->assertConflict()->assertJsonPath('error_code', 'VERIFIED_SENDER_REQUIRED');
    }

    public function test_enrolled_recipient_limit_is_enforced_server_side(): void
    {
        $context = $this->activeContext();
        CompanyEntitlement::query()->where('company_id', $context['company']->id)->update(['limits' => ['active_sequences' => 10, 'sending_identities' => 5, 'enrolled_recipients' => 0, 'daily_messages' => 100]]);

        $this->postJson('/api/v1/outreach/sequences/'.$context['sequence']->id.'/enrollments', ['recipient_type' => 'CONTACT', 'recipient_ids' => [$context['contact']->id], 'idempotency_key' => 'limit'], $this->headers($context['company']->id))->assertConflict()->assertJsonPath('error_code', 'SUBSCRIPTION_LIMIT_REACHED');

        $this->assertSame(0, OutreachEnrollment::count());
    }

    public function test_message_snapshot_is_unchanged_after_template_and_contact_edits(): void
    {
        $context = $this->activeContext();
        $this->postJson('/api/v1/outreach/sequences/'.$context['sequence']->id.'/enrollments', ['recipient_type' => 'CONTACT', 'recipient_ids' => [$context['contact']->id], 'idempotency_key' => 'snapshot'], $this->headers($context['company']->id))->assertCreated();
        $message = OutreachMessage::query()->firstOrFail();
        $subject = $message->subject;
        $body = $message->body_text;

        $context['contact']->update(['first_name' => 'Changed']);
        $context['template']->update(['subject' => 'Changed subject', 'body_text' => 'Changed body']);

        $this->assertSame($subject, $message->fresh()->subject);
        $this->assertSame($body, $message->fresh()->body_text);
        $this->assertSame($context['template']->id, $message->fresh()->template_id);
        $this->assertSame($context['connection']->id, $message->fresh()->provider_connection_id);
    }

    /** @return array<string, mixed> */
    private function activeContext(): array
    {
        $context = $this->stage11OutreachContext();
        app(SequenceService::class)->transition($context['sequence'], 'ACTIVE', $context['company']->id, $context['user']->id);

        return $context;
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function sequencePayload(array $context): array
    {
        return ['name' => 'Lifecycle '.fake()->uuid(), 'sending_identity_id' => $context['identity']->id, 'timezone' => 'Asia/Karachi', 'allowed_weekdays' => [1, 2, 3, 4, 5], 'send_window_start' => '09:00', 'send_window_end' => '17:00', 'track_opens' => true, 'track_clicks' => true, 'stop_on_reply' => true, 'steps' => [['type' => 'EMAIL', 'template_id' => $context['template']->id, 'subject' => $context['template']->subject, 'body_text' => $context['template']->body_text, 'wait_minutes' => 0], ['type' => 'WAIT', 'wait_minutes' => 1440], ['type' => 'EMAIL', 'subject' => 'Follow up {{contact.first_name}}', 'body_text' => 'Following up.', 'wait_minutes' => 0]]];
    }

    /** @return array<string, string> */
    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId];
    }
}
