<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CrmAccount;
use App\Models\CrmActivity;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\CrmImport;
use App\Models\CrmImportRow;
use App\Models\CrmLead;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\CrmScoreEvent;
use App\Models\CrmScoreRule;
use App\Models\CrmTag;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmSecurityAccountingBoundaryFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_stage_nine_factories_create_valid_records(): void
    {
        $models = [
            CrmAccount::factory()->create(), CrmContact::factory()->create(), CrmLead::factory()->create(),
            CrmPipeline::factory()->create(), CrmPipelineStage::factory()->create(), CrmDeal::factory()->create(),
            CrmActivity::factory()->create(), CrmTag::factory()->create(), CrmImport::factory()->create(),
            CrmImportRow::factory()->create(), CrmScoreRule::factory()->create(), CrmScoreEvent::factory()->create(),
        ];

        foreach ($models as $model) {
            $this->assertTrue($model->exists, $model::class.' factory did not persist a model.');
        }
    }

    public function test_crm_operational_lifecycle_cannot_create_accounting_artifacts(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $account = $this->postJson('/api/v1/crm/accounts', ['name' => 'Firewall Account', 'account_type' => 'BUSINESS', 'country' => 'PK', 'status' => 'PROSPECT'], $headers)->assertSuccessful()->json();
        $contact = $this->postJson('/api/v1/crm/contacts', ['account_id' => $account['id'], 'first_name' => 'Firewall', 'last_name' => 'Contact', 'email' => 'firewall@example.com', 'status' => 'ACTIVE'], $headers)->assertSuccessful()->json();
        $lead = $this->postJson('/api/v1/crm/leads', ['account_id' => $account['id'], 'contact_id' => $contact['id'], 'first_name' => 'Firewall', 'last_name' => 'Lead', 'company_name' => 'Firewall Account', 'email' => 'lead@firewall.example', 'status' => 'NEW', 'estimated_value' => 1_000_00, 'currency' => 'PKR'], $headers)->assertSuccessful()->json();
        $this->postJson('/api/v1/crm/leads/'.$lead['id'].'/transition', ['status' => 'QUALIFIED'], $headers)->assertSuccessful();
        $this->postJson('/api/v1/crm/leads/'.$lead['id'].'/convert', ['idempotency_key' => 'firewall-convert', 'create_account' => false, 'create_contact' => false, 'create_deal' => true, 'account_id' => $account['id'], 'contact_id' => $contact['id'], 'pipeline_id' => $context['pipeline']->id, 'pipeline_stage_id' => $context['stages']['open']->id], $headers)->assertSuccessful();
        $deal = CrmLead::query()->findOrFail($lead['id'])->convertedDeal;
        $this->postJson('/api/v1/crm/deals/'.$deal->id.'/transition', ['pipeline_stage_id' => $context['stages']['won']->id], $headers)->assertSuccessful();
        $lostDeal = $this->postJson('/api/v1/crm/deals', ['account_id' => $account['id'], 'primary_contact_id' => $contact['id'], 'pipeline_id' => $context['pipeline']->id, 'pipeline_stage_id' => $context['stages']['open']->id, 'title' => 'Lost opportunity', 'amount' => 2_000_00, 'currency' => 'PKR'], $headers)->assertSuccessful()->json();
        $this->postJson('/api/v1/crm/deals/'.$lostDeal['id'].'/transition', ['pipeline_stage_id' => $context['stages']['lost']->id, 'loss_reason' => 'Budget'], $headers)->assertSuccessful();
        $activity = $this->postJson('/api/v1/crm/activities', ['related_type' => 'deal', 'related_id' => $deal->id, 'type' => 'TASK', 'subject' => 'Handoff', 'status' => 'PENDING', 'priority' => 'HIGH'], $headers)->assertSuccessful()->json();
        $this->postJson('/api/v1/crm/activities/'.$activity['id'].'/transition', ['status' => 'COMPLETED'], $headers)->assertSuccessful();
        $this->postJson('/api/v1/crm/score-rules', ['name' => 'Has email', 'target_type' => 'LEAD', 'field' => 'email', 'operator' => 'NOT_EMPTY', 'points' => 10, 'position' => 1], $headers)->assertSuccessful();
        $this->postJson('/api/v1/crm/scores/lead/'.$lead['id'].'/recalculate', [], $headers)->assertSuccessful();
        $import = $this->postJson('/api/v1/crm/imports/preview', ['entity_type' => 'ACCOUNT', 'filename' => 'accounts.csv', 'csv' => "Name\nImported Firewall\n", 'mapping' => ['Name' => 'name']], $headers)->assertSuccessful()->json();
        $this->postJson('/api/v1/crm/imports/'.$import['id'].'/confirm', ['idempotency_key' => 'firewall-import'], $headers)->assertSuccessful();

        $this->assertSame(['journals' => 0, 'journal_lines' => 0, 'invoices' => 0, 'payments' => 0], $this->accountingCounts());
    }

    public function test_crm_permissions_are_enforced_for_read_and_mutation(): void
    {
        [$user, $company] = $this->actingAsCompanyUser([]);
        $account = CrmAccount::factory()->for($company)->create(['created_by' => $user->id]);
        $headers = $this->headers($company->id);

        $this->getJson('/api/v1/crm/accounts', $headers)->assertForbidden();
        $this->postJson('/api/v1/crm/accounts', ['name' => 'Denied'], $headers)->assertForbidden();
        $this->getJson('/api/v1/crm/accounts/'.$account->id, $headers)->assertForbidden();
        $this->getJson('/api/v1/crm/dashboard', $headers)->assertForbidden();
    }

    public function test_cross_company_idor_is_rejected_for_all_crm_aggregate_roots(): void
    {
        $context = $this->stage9CrmContext();
        $foreign = $this->foreignCrmRecords();
        $headers = $this->headers($context['company']->id);
        foreach (['accounts' => $foreign['account'], 'contacts' => $foreign['contact'], 'leads' => $foreign['lead'], 'deals' => $foreign['deal'], 'pipelines' => $foreign['pipeline'], 'activities' => $foreign['activity'], 'tags' => $foreign['tag'], 'imports' => $foreign['import']] as $resource => $model) {
            $this->getJson('/api/v1/crm/'.$resource.'/'.$model->id, $headers)->assertNotFound();
        }
        $this->putJson('/api/v1/crm/score-rules/'.$foreign['rule']->id, ['name' => 'Denied', 'target_type' => 'LEAD', 'field' => 'email', 'operator' => 'NOT_EMPTY', 'points' => 1, 'position' => 1], $headers)->assertNotFound();
        $this->postJson('/api/v1/crm/deals/'.$foreign['deal']->id.'/customer-handoff', ['idempotency_key' => 'foreign-handoff', 'name' => 'Denied', 'type' => 'business', 'country' => 'PK', 'payment_terms_days' => 30, 'currency' => 'PKR'], $headers)->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function foreignCrmRecords(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $pipeline = CrmPipeline::factory()->for($company)->create(['created_by' => $user->id]);
        $stage = CrmPipelineStage::factory()->for($company)->for($pipeline, 'pipeline')->create();
        $account = CrmAccount::factory()->for($company)->create(['created_by' => $user->id]);
        $contact = CrmContact::factory()->for($company)->for($account, 'account')->create(['created_by' => $user->id]);
        $lead = CrmLead::factory()->for($company)->create(['created_by' => $user->id]);
        $deal = CrmDeal::factory()->for($company)->for($account, 'account')->for($pipeline, 'pipeline')->for($stage, 'stage')->create(['status' => 'WON', 'created_by' => $user->id]);
        $activity = CrmActivity::factory()->for($company)->for($lead, 'activityable')->create(['created_by' => $user->id]);
        $tag = CrmTag::factory()->for($company)->create(['created_by' => $user->id]);
        $import = CrmImport::factory()->for($company)->create(['created_by' => $user->id]);
        $rule = CrmScoreRule::factory()->for($company)->create(['created_by' => $user->id]);

        return compact('account', 'contact', 'lead', 'deal', 'pipeline', 'activity', 'tag', 'import', 'rule');
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
