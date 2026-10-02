<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CrmAccount;
use App\Models\CrmActivity;
use App\Models\CrmDeal;
use App\Models\CrmImport;
use App\Models\CrmLead;
use App\Models\Invoice;
use App\Models\Journal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class CrmImportReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_import_preview_validates_detects_duplicates_and_persists_no_business_records(): void
    {
        $context = $this->stage9CrmContext();
        $context['account']->update(['name' => 'Existing Account', 'ntn' => '1234567']);
        $before = CrmAccount::count();
        $csv = "Name,Email,NTN\nNew Account,new@example.com,7654321\nExisting Account,old@example.com,1234567\n,invalid,12\n=FORMULA,formula@example.com,1111111\n";
        $import = $this->postJson('/api/v1/crm/imports/preview', ['entity_type' => 'ACCOUNT', 'filename' => 'accounts.csv', 'csv' => $csv, 'mapping' => ['Name' => 'name', 'Email' => 'email', 'NTN' => 'ntn']], $this->headers($context['company']->id))
            ->assertSuccessful()->assertJsonPath('summary.total', 4)->assertJsonPath('summary.duplicates', 1)->assertJsonPath('summary.invalid', 1)->json();

        $this->assertSame($before, CrmAccount::count());
        $this->assertSame('POSSIBLE_DUPLICATE', $import['rows'][1]['state']);
        $this->assertSame('INVALID', $import['rows'][2]['state']);
        $this->assertSame("'=FORMULA", $import['rows'][3]['source']['Name']);
    }

    public function test_import_confirmation_is_idempotent_and_accounting_neutral(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $preview = $this->postJson('/api/v1/crm/imports/preview', ['entity_type' => 'LEAD', 'filename' => 'leads.csv', 'csv' => "First,Last,Email,Value\nAli,Khan,ali@example.com,250000\n", 'mapping' => ['First' => 'first_name', 'Last' => 'last_name', 'Email' => 'email', 'Value' => 'estimated_value']], $headers)->assertSuccessful()->json();
        $before = CrmLead::count();
        $payload = ['idempotency_key' => 'import-confirm-1'];
        $this->postJson('/api/v1/crm/imports/'.$preview['id'].'/confirm', $payload, $headers)->assertSuccessful()->assertJsonPath('status', 'CONFIRMED');
        $this->postJson('/api/v1/crm/imports/'.$preview['id'].'/confirm', $payload, $headers)->assertSuccessful();

        $this->assertSame($before + 1, CrmLead::count());
        $this->assertSame(0, Journal::count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_contact_and_account_imports_confirm_valid_rows_without_merging_duplicates(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $accountPreview = $this->postJson('/api/v1/crm/imports/preview', ['entity_type' => 'ACCOUNT', 'filename' => 'accounts.csv', 'csv' => "Name,Email\nImported Account,imported@example.com\n", 'mapping' => ['Name' => 'name', 'Email' => 'email']], $headers)->assertSuccessful()->json();
        $this->postJson('/api/v1/crm/imports/'.$accountPreview['id'].'/confirm', ['idempotency_key' => 'accounts-confirm'], $headers)->assertSuccessful();

        $contactPreview = $this->postJson('/api/v1/crm/imports/preview', ['entity_type' => 'CONTACT', 'filename' => 'contacts.csv', 'csv' => "First,Last,Email,Account\nSara,Ali,sara@example.com,{$context['account']->id}\n", 'mapping' => ['First' => 'first_name', 'Last' => 'last_name', 'Email' => 'email', 'Account' => 'account_id']], $headers)->assertSuccessful()->json();
        $this->postJson('/api/v1/crm/imports/'.$contactPreview['id'].'/confirm', ['idempotency_key' => 'contacts-confirm'], $headers)->assertSuccessful();

        $this->assertDatabaseHas('crm_accounts', ['company_id' => $context['company']->id, 'name' => 'Imported Account']);
        $this->assertDatabaseHas('crm_contacts', ['company_id' => $context['company']->id, 'email' => 'sara@example.com', 'account_id' => $context['account']->id]);
    }

    public function test_malformed_unsupported_and_cross_company_import_requests_fail_cleanly(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $this->postJson('/api/v1/crm/imports/preview', ['entity_type' => 'LEAD', 'filename' => 'leads.txt', 'csv' => "Name\nAli", 'mapping' => ['Name' => 'first_name']], $headers)->assertUnprocessable()->assertJsonValidationErrors('filename');
        $this->postJson('/api/v1/crm/imports/preview', ['entity_type' => 'LEAD', 'filename' => 'leads.csv', 'csv' => 'Name', 'mapping' => ['Name' => 'first_name']], $headers)->assertUnprocessable()->assertJsonPath('error_code', 'CRM_IMPORT_EMPTY');
        $this->postJson('/api/v1/crm/imports/preview', ['entity_type' => 'DEAL', 'filename' => 'deals.csv', 'csv' => "Name\nDeal", 'mapping' => ['Name' => 'name']], $headers)->assertUnprocessable()->assertJsonValidationErrors('entity_type');

        $foreign = CrmImport::factory()->for(Company::factory()->create())->create();
        $this->getJson('/api/v1/crm/imports/'.$foreign->id, $headers)->assertNotFound();
        $this->postJson('/api/v1/crm/imports/'.$foreign->id.'/confirm', ['idempotency_key' => 'foreign'], $headers)->assertNotFound();
    }

    public function test_dashboard_and_pipeline_reports_use_integer_weighting_and_operational_metrics(): void
    {
        $context = $this->stage9CrmContext();
        CrmDeal::factory()->for($context['company'])->for($context['account'], 'account')->for($context['pipeline'], 'pipeline')->for($context['stages']['open'], 'stage')->create(['owner_id' => $context['user']->id, 'amount' => 1_000_01, 'probability_bps' => 3333, 'status' => 'OPEN', 'created_by' => $context['user']->id]);
        CrmDeal::factory()->for($context['company'])->for($context['account'], 'account')->for($context['pipeline'], 'pipeline')->for($context['stages']['won'], 'stage')->create(['owner_id' => $context['user']->id, 'amount' => 2_000_00, 'probability_bps' => 10000, 'status' => 'WON', 'closed_at' => now(), 'actual_close_date' => now(), 'created_by' => $context['user']->id]);
        CrmActivity::factory()->for($context['company'])->for($context['lead'], 'activityable')->create(['owner_id' => $context['user']->id, 'due_at' => now()->subDay(), 'status' => 'PENDING', 'created_by' => $context['user']->id]);
        $headers = $this->headers($context['company']->id);

        $this->getJson('/api/v1/crm/dashboard', $headers)->assertSuccessful()->assertJsonPath('data.openDeals', 1)->assertJsonPath('data.pipelineValue', 1_000_01)->assertJsonPath('data.weightedPipelineValue', intdiv(1_000_01 * 3333, 10000))->assertJsonPath('data.wonDeals', 1)->assertJsonPath('data.overdueActivities', 1);
        $this->getJson('/api/v1/crm/reports/pipeline', $headers)->assertSuccessful()->assertJsonFragment(['status' => 'OPEN', 'count' => 1])->assertJsonCount(1, 'data.owners');
        $this->getJson('/api/v1/crm/reports/activities', $headers)->assertSuccessful()->assertJsonCount(1, 'data.byOwner');
    }

    public function test_report_aggregate_contracts_remain_integers_with_string_fetches(): void
    {
        $context = $this->stage9CrmContext();
        $context['lead']->update(['source' => 'Portability fixture', 'status' => 'CONVERTED']);
        CrmDeal::factory()->for($context['company'])->for($context['account'], 'account')->for($context['pipeline'], 'pipeline')->for($context['stages']['won'], 'stage')->create([
            'owner_id' => $context['user']->id, 'amount' => 200001, 'probability_bps' => 10000,
            'status' => 'WON', 'expected_close_date' => '2026-06-30', 'created_by' => $context['user']->id,
        ]);
        CrmActivity::factory()->for($context['company'])->for($context['lead'], 'activityable')->create([
            'owner_id' => $context['user']->id, 'type' => 'TASK', 'due_at' => now()->subDay(), 'created_by' => $context['user']->id,
        ]);
        CrmActivity::factory()->completed()->for($context['company'])->for($context['lead'], 'activityable')->create([
            'owner_id' => $context['user']->id, 'type' => 'TASK', 'created_by' => $context['user']->id,
        ]);
        $headers = $this->headers($context['company']->id);
        $pdo = DB::connection()->getPdo();
        $original = $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

        try {
            $this->assertSame('200001', CrmDeal::query()->where('company_id', $context['company']->id)->sum('amount'));
            $this->getJson('/api/v1/crm/dashboard', $headers)->assertSuccessful()
                ->assertJsonPath('data.leadsBySource.0.leads', 1)->assertJsonPath('data.leadsBySource.0.converted', 1);
            $this->getJson('/api/v1/crm/reports/pipeline', $headers)->assertSuccessful()
                ->assertJsonPath('data.owners.0.deals', 1)->assertJsonPath('data.owners.0.won', 1)->assertJsonPath('data.owners.0.won_value', 200001)
                ->assertJsonPath('data.expectedCloseDistribution.0.deals', 1)->assertJsonPath('data.expectedCloseDistribution.0.value', 200001);
            $activities = $this->getJson('/api/v1/crm/reports/activities', $headers)->assertSuccessful()
                ->assertJsonPath('data.byOwner.0.total', 2)->assertJsonPath('data.byOwner.0.completed', 1)->assertJsonPath('data.byOwner.0.overdue', 1);
            foreach ($activities->json('data.byType') as $group) {
                $this->assertSame(1, $group['count']);
            }
        } finally {
            $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $original);
        }
    }

    /** @return array<string, string> */
    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId];
    }
}
