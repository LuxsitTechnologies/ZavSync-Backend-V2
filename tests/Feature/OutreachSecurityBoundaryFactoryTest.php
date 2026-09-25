<?php

namespace Tests\Feature;

use App\Models\CompanyEntitlement;
use App\Models\CustomerPayment;
use App\Models\EmailProviderConnection;
use App\Models\EmailSendingIdentity;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\OutreachEnrollment;
use App\Models\OutreachMessage;
use App\Models\OutreachMessageEvent;
use App\Models\OutreachMessageLink;
use App\Models\OutreachSendAttempt;
use App\Models\OutreachSequence;
use App\Models\OutreachSequenceStep;
use App\Models\OutreachSuppression;
use App\Models\PayrollPayment;
use App\Models\PlatformModule;
use App\Models\SupplierPayment;
use App\Services\Outreach\SequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutreachSecurityBoundaryFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_stage_eleven_factories_create_valid_records(): void
    {
        $models = [EmailProviderConnection::factory()->create(), EmailSendingIdentity::factory()->create(), EmailTemplate::factory()->create(), OutreachSequence::factory()->create(), OutreachSequenceStep::factory()->create(), OutreachEnrollment::factory()->create(), OutreachMessage::factory()->create(), OutreachSendAttempt::factory()->create(), OutreachMessageEvent::factory()->create(), OutreachSuppression::factory()->create(), OutreachMessageLink::factory()->create()];

        foreach ($models as $model) {
            $this->assertTrue($model->exists, $model::class.' factory did not persist a model.');
        }
    }

    public function test_outreach_permissions_are_enforced_for_read_and_mutation(): void
    {
        [, $company] = $this->actingAsCompanyUser([]);
        PlatformModule::query()->create(['key' => 'outreach', 'name' => 'Outreach']);
        CompanyEntitlement::factory()->for($company)->create(['module_key' => 'outreach', 'is_enabled' => true]);

        $this->getJson('/api/v1/outreach/sequences', $this->headers($company->id))->assertForbidden();
        $this->postJson('/api/v1/outreach/templates', ['name' => 'Denied', 'subject' => 'Denied', 'body_text' => 'Denied'], $this->headers($company->id))->assertForbidden();
    }

    public function test_cross_company_resources_return_not_found(): void
    {
        $context = $this->stage11OutreachContext();
        $foreign = $this->stage11OutreachContext();
        $this->actingAs($context['user']);

        $this->getJson('/api/v1/outreach/providers/'.$foreign['connection']->id, $this->headers($context['company']->id))->assertNotFound();
        $this->patchJson('/api/v1/outreach/providers/'.$foreign['connection']->id, ['name' => 'Denied'], $this->headers($context['company']->id))->assertNotFound();
        $this->postJson('/api/v1/outreach/templates/'.$foreign['template']->id.'/preview', ['contact_id' => $context['contact']->id], $this->headers($context['company']->id))->assertNotFound();
        $this->getJson('/api/v1/outreach/sequences/'.$foreign['sequence']->id, $this->headers($context['company']->id))->assertNotFound();
        $this->postJson('/api/v1/outreach/sequences', ['name' => 'Foreign sender', 'sending_identity_id' => $foreign['identity']->id, 'timezone' => 'UTC', 'allowed_weekdays' => [1], 'send_window_start' => '09:00', 'send_window_end' => '17:00', 'steps' => [['type' => 'EMAIL', 'subject' => 'Hi', 'body_text' => 'Body', 'wait_minutes' => 0]]], $this->headers($context['company']->id))->assertUnprocessable();

        $this->assertSame(0, OutreachMessage::query()->where('company_id', $context['company']->id)->count());
    }

    public function test_company_without_outreach_entitlement_receives_structured_error(): void
    {
        [, $company] = $this->actingAsCompanyUser(['outreach.view']);

        $this->getJson('/api/v1/outreach/sequences', $this->headers($company->id))->assertForbidden()->assertJsonPath('error_code', 'MODULE_NOT_ENTITLED');
    }

    public function test_outreach_lifecycle_creates_zero_accounting_artifacts(): void
    {
        $context = $this->stage11OutreachContext();
        app(SequenceService::class)->transition($context['sequence'], 'ACTIVE', $context['company']->id, $context['user']->id);

        $this->postJson('/api/v1/outreach/sequences/'.$context['sequence']->id.'/enrollments', ['recipient_type' => 'CONTACT', 'recipient_ids' => [$context['contact']->id], 'idempotency_key' => 'firewall'], $this->headers($context['company']->id))->assertCreated();
        $this->postJson('/api/v1/outreach/suppressions', ['email' => 'blocked@example.test', 'reason' => 'MANUAL'], $this->headers($context['company']->id))->assertCreated();

        $this->assertSame(['journals' => 0, 'lines' => 0, 'invoices' => 0, 'customer_payments' => 0, 'supplier_payments' => 0, 'payroll_payments' => 0], ['journals' => Journal::count(), 'lines' => JournalLine::count(), 'invoices' => Invoice::count(), 'customer_payments' => CustomerPayment::count(), 'supplier_payments' => SupplierPayment::count(), 'payroll_payments' => PayrollPayment::count()]);
    }

    public function test_provider_resource_serialization_never_contains_secret_fields(): void
    {
        $connection = EmailProviderConnection::factory()->create(['credentials' => ['password' => 'secret'], 'access_token' => 'access', 'refresh_token' => 'refresh', 'webhook_secret' => 'webhook']);

        $serialized = $connection->toArray();

        foreach (['credentials', 'configuration', 'access_token', 'refresh_token', 'webhook_secret'] as $key) {
            $this->assertArrayNotHasKey($key, $serialized);
        }
    }

    /** @return array<string, string> */
    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId];
    }
}
