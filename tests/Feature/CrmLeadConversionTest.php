<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CrmAccount;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\JournalLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmLeadConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_crud_search_filter_and_controlled_lifecycle(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $lead = $this->postJson('/api/v1/crm/leads', $this->leadPayload(['first_name' => 'Searchable']), $headers)->assertSuccessful()->assertJsonPath('status', 'NEW')->json();
        $this->getJson('/api/v1/crm/leads?search=Searchable&status=NEW&mine=0', $headers)->assertSuccessful()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/crm/leads/'.$lead['id'].'/transition', ['status' => 'CONTACTED'], $headers)->assertSuccessful()->assertJsonPath('status', 'CONTACTED');
        $this->postJson('/api/v1/crm/leads/'.$lead['id'].'/transition', ['status' => 'QUALIFIED', 'qualification_notes' => 'Budget confirmed'], $headers)->assertSuccessful()->assertJsonPath('qualificationNotes', 'Budget confirmed');
        $this->postJson('/api/v1/crm/leads/'.$lead['id'].'/transition', ['status' => 'NEW'], $headers)->assertStatus(409)->assertJsonPath('error_code', 'CRM_LEAD_TRANSITION_INVALID');
    }

    public function test_qualified_lead_conversion_is_transactional_traceable_and_has_no_accounting_effect(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $before = $this->accountingCounts();
        $response = $this->postJson('/api/v1/crm/leads/'.$context['lead']->id.'/convert', $this->conversionPayload($context), $headers)
            ->assertSuccessful()->assertJsonPath('status', 'CONVERTED');

        $lead = CrmLead::query()->findOrFail($context['lead']->id);
        $this->assertNotNull($lead->converted_account_id);
        $this->assertNotNull($lead->converted_contact_id);
        $this->assertNotNull($lead->converted_deal_id);
        $this->assertSame($context['company']->id, CrmDeal::query()->findOrFail($lead->converted_deal_id)->company_id);
        $this->assertSame($before, $this->accountingCounts());
        $response->assertJsonPath('convertedDealId', $lead->converted_deal_id);
    }

    public function test_lead_conversion_retry_is_idempotent_and_does_not_duplicate_records(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $payload = $this->conversionPayload($context);
        $first = $this->postJson('/api/v1/crm/leads/'.$context['lead']->id.'/convert', $payload, $headers)->assertSuccessful()->json();
        $counts = [CrmAccount::count(), CrmContact::count(), CrmDeal::count()];
        $second = $this->postJson('/api/v1/crm/leads/'.$context['lead']->id.'/convert', $payload, $headers)->assertSuccessful()->json();

        $this->assertSame($first['convertedAccountId'], $second['convertedAccountId']);
        $this->assertSame($counts, [CrmAccount::count(), CrmContact::count(), CrmDeal::count()]);
    }

    public function test_conversion_can_link_existing_account_and_contact(): void
    {
        $context = $this->stage9CrmContext();
        $payload = $this->conversionPayload($context, ['create_account' => false, 'create_contact' => false, 'account_id' => $context['account']->id, 'contact_id' => $context['contact']->id]);
        $this->postJson('/api/v1/crm/leads/'.$context['lead']->id.'/convert', $payload, $this->headers($context['company']->id))
            ->assertSuccessful()->assertJsonPath('convertedAccountId', $context['account']->id)->assertJsonPath('convertedContactId', $context['contact']->id);
    }

    public function test_unqualified_and_cross_company_conversion_inputs_are_rejected(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $newLead = CrmLead::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        $this->postJson('/api/v1/crm/leads/'.$newLead->id.'/convert', $this->conversionPayload($context), $headers)->assertStatus(409)->assertJsonPath('error_code', 'CRM_LEAD_NOT_QUALIFIED');

        $foreign = CrmAccount::factory()->for(Company::factory()->create())->create();
        $this->postJson('/api/v1/crm/leads/'.$context['lead']->id.'/convert', $this->conversionPayload($context, ['account_id' => $foreign->id, 'create_account' => false]), $headers)->assertUnprocessable()->assertJsonValidationErrors('account_id');
    }

    public function test_converted_lead_is_preserved_and_cannot_be_edited_or_deleted(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $this->postJson('/api/v1/crm/leads/'.$context['lead']->id.'/convert', $this->conversionPayload($context), $headers)->assertSuccessful();
        $this->putJson('/api/v1/crm/leads/'.$context['lead']->id, $this->leadPayload(), $headers)->assertStatus(409)->assertJsonPath('error_code', 'CRM_LEAD_CONVERTED');
        $this->deleteJson('/api/v1/crm/leads/'.$context['lead']->id, [], $headers)->assertStatus(409);
        $this->assertDatabaseHas('crm_leads', ['id' => $context['lead']->id, 'status' => 'CONVERTED']);
    }

    /** @return array<string, mixed> */
    private function leadPayload(array $overrides = []): array
    {
        return [...['first_name' => 'Lead', 'last_name' => 'Person', 'company_name' => 'Lead Company', 'job_title' => 'CFO', 'email' => 'lead@example.com', 'phone' => '+923001234567', 'website' => 'https://lead.example', 'source' => 'Website', 'status' => 'NEW', 'estimated_value' => 1_500_000, 'currency' => 'PKR', 'expected_timeframe' => '2026-12-31', 'interest' => 'ERP', 'notes' => 'Interested', 'qualification_notes' => null], ...$overrides];
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function conversionPayload(array $context, array $overrides = []): array
    {
        return [...['idempotency_key' => 'lead-convert-'.$context['lead']->id, 'create_account' => true, 'create_contact' => true, 'create_deal' => true, 'pipeline_id' => $context['pipeline']->id, 'pipeline_stage_id' => $context['stages']['open']->id, 'deal_title' => 'Converted opportunity'], ...$overrides];
    }

    /** @return array<string, int> */
    private function accountingCounts(): array
    {
        return ['journals' => Journal::count(), 'journal_lines' => JournalLine::count(), 'invoices' => Invoice::count(), 'payments' => CustomerPayment::count()];
    }

    /** @return array<string, string> */
    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId];
    }
}
