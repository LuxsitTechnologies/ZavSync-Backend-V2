<?php

namespace Tests\Feature\Stage13;

use App\Models\Account;
use App\Models\CrmActivity;
use App\Models\FinancialAccount;
use App\Models\OperationalPrioritySignal;
use App\Services\Ai\AnomalyDetectionService;
use App\Services\Ai\ManagementBriefingService;
use App\Services\Ai\OperationalSignalService;
use App\Services\Ai\ScenarioAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntelligenceAccountingFirewallTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_intelligence_lifecycle_creates_no_financial_or_operational_side_effects(): void
    {
        $context = $this->stage13IntelligenceContext();
        $account = Account::factory()->for($context['company'])->create(['opening_balance' => 500000, 'created_by' => $context['user']->id]);
        FinancialAccount::factory()->for($context['company'])->create(['gl_account_id' => $account->id, 'currency' => 'PKR', 'created_by' => $context['user']->id]);
        CrmActivity::factory()->for($context['company'])->create(['owner_id' => $context['user']->id, 'created_by' => $context['user']->id, 'status' => 'PENDING', 'due_at' => now()->subDay()]);
        OperationalPrioritySignal::factory()->for($context['company'])->create(['source_module' => 'crm', 'category' => 'CRM', 'explanation_metadata' => ['required_permission' => 'crm.view']]);
        $protectedTables = ['journals', 'journal_lines', 'invoices', 'customer_payments', 'supplier_payments', 'payroll_payments', 'inventory_movements', 'bank_reconciliations', 'bank_reconciliation_matches', 'outreach_messages', 'outreach_send_attempts'];
        $before = collect($protectedTables)->mapWithKeys(fn (string $table): array => [$table => $this->countTable($table)])->all();

        app(OperationalSignalService::class)->refresh($context['company']->id);
        app(AnomalyDetectionService::class)->evaluate([100000, 110000, 90000, 150000], 2000);
        app(ScenarioAnalysisService::class)->calculate($context['company']->id, $context['user'], ['name' => 'Downside', 'scenario_type' => 'REVENUE_CHANGE', 'assumptions' => ['change_bps' => -1000]], 'firewall-scenario');
        app(ManagementBriefingService::class)->prepare($context['company']->id, $context['user'], 'TODAY');

        foreach ($protectedTables as $table) {
            $this->assertSame($before[$table], $this->countTable($table), "Stage 13 mutated {$table}.");
        }
        $this->assertDatabaseCount('intelligence_scenarios', 1);
        $this->assertDatabaseHas('intelligence_briefings', ['company_id' => $context['company']->id]);
        $this->assertDatabaseHas('operational_priority_signals', ['company_id' => $context['company']->id]);
    }

    private function countTable(string $table): int
    {
        return DB::table($table)->count();
    }
}
