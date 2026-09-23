<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Company;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_draft_with_deterministic_equal_monthly_allocation(): void
    {
        $context = $this->stage7PlanningContext();
        $response = $this->postJson('/api/v1/planning/budgets', ['fiscal_year_id' => $context['fiscalYear']->id, 'name' => 'Operating Budget', 'currency' => 'PKR', 'lines' => [['account_id' => $context['accounts']['expense']->id, 'annual_amount' => 100, 'distribution' => 'equal']]], $this->headers($context['company']->id));

        $response->assertCreated()->assertJsonPath('status', 'draft')->assertJsonCount(3, 'lines');
        $amounts = BudgetLine::query()->where('budget_id', $response->json('id'))->with('period')->get()->sortBy(fn (BudgetLine $line): string => $line->period->start_date->format('Y-m-d'))->pluck('amount')->values()->all();
        $this->assertSame([34, 33, 33], $amounts);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_validates_accounts_duplicates_and_tenant_boundaries(): void
    {
        $context = $this->stage7PlanningContext();
        $other = Company::factory()->create();
        $foreign = Account::factory()->for($other)->expense()->create(['created_by' => $context['user']->id]);
        $payload = ['fiscal_year_id' => $context['fiscalYear']->id, 'name' => 'Invalid Budget', 'currency' => 'PKR', 'lines' => [['account_id' => $foreign->id, 'annual_amount' => 100, 'distribution' => 'equal']]];

        $this->postJson('/api/v1/planning/budgets', $payload, $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->postJson('/api/v1/planning/budgets', [...$payload, 'lines' => [['account_id' => $context['accounts']['expense']->id, 'annual_amount' => 100], ['account_id' => $context['accounts']['expense']->id, 'annual_amount' => 200]]], $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors('lines');
        $inactive = Account::factory()->for($context['company'])->expense()->create(['is_active' => false, 'created_by' => $context['user']->id]);
        $this->postJson('/api/v1/planning/budgets', [...$payload, 'lines' => [['account_id' => $inactive->id, 'annual_amount' => 100]]], $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors('lines');
    }

    public function test_manual_monthly_distribution_must_equal_annual_amount(): void
    {
        $context = $this->stage7PlanningContext();
        $periods = collect($context['periods'])->map(fn ($period, int $index): array => ['period_id' => $period->id, 'amount' => ($index + 1) * 10])->all();
        $payload = ['fiscal_year_id' => $context['fiscalYear']->id, 'name' => 'Manual Budget', 'currency' => 'PKR', 'lines' => [['account_id' => $context['accounts']['revenue']->id, 'annual_amount' => 60, 'distribution' => 'manual', 'periods' => $periods]]];

        $this->postJson('/api/v1/planning/budgets', $payload, $this->headers($context['company']->id))->assertCreated()->assertJsonPath('lines.2.amount', 30);
        $this->postJson('/api/v1/planning/budgets', [...$payload, 'name' => 'Invalid Manual', 'lines' => [[...$payload['lines'][0], 'annual_amount' => 61]]], $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors('annual_amount');
    }

    public function test_submit_approve_activate_and_revision_preserve_history(): void
    {
        $context = $this->stage7PlanningContext();
        $budgetId = $this->createBudget($context, 1200);
        $headers = $this->headers($context['company']->id);

        $this->postJson("/api/v1/planning/budgets/{$budgetId}/submit", [], $headers)->assertOk()->assertJsonPath('status', 'submitted');
        $this->postJson("/api/v1/planning/budgets/{$budgetId}/approve", [], $headers)->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson("/api/v1/planning/budgets/{$budgetId}/activate", [], $headers)->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('is_active', true);
        $this->patchJson("/api/v1/planning/budgets/{$budgetId}", ['name' => 'Overwritten'], $headers)->assertUnprocessable();
        $revision = $this->postJson("/api/v1/planning/budgets/{$budgetId}/revise", [], $headers)->assertCreated()->assertJsonPath('version', 2)->assertJsonPath('status', 'draft');

        $this->assertDatabaseHas('budgets', ['id' => $budgetId, 'name' => 'Operating Budget', 'status' => 'active']);
        $this->assertDatabaseHas('budgets', ['id' => $revision->json('id'), 'based_on_budget_id' => $budgetId, 'version' => 2]);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id, 'module' => 'budget', 'action' => 'activate']);
    }

    public function test_budget_actual_uses_posted_gl_and_returns_integer_safe_variance(): void
    {
        $context = $this->stage7PlanningContext();
        $budgetId = $this->createBudget($context, 3000);
        app(JournalPostingService::class)->post($context['company']->id, $context['user'], ['posting_date' => '2027-01-15', 'description' => 'Posted expense', 'lines' => [['account_id' => $context['accounts']['expense']->id, 'debit' => 800, 'credit' => 0], ['account_id' => $context['accounts']['cash']->id, 'debit' => 0, 'credit' => 800]]]);
        app(JournalPostingService::class)->saveDraft($context['company']->id, $context['user'], ['posting_date' => '2027-01-20', 'description' => 'Draft excluded', 'lines' => [['account_id' => $context['accounts']['expense']->id, 'debit' => 500, 'credit' => 0], ['account_id' => $context['accounts']['cash']->id, 'debit' => 0, 'credit' => 500]]]);

        $this->getJson("/api/v1/planning/budgets/{$budgetId}/actual?from=2027-01-01&to=2027-01-31", $this->headers($context['company']->id))->assertOk()->assertJsonPath('rows.0.budget', 1000)->assertJsonPath('rows.0.actual', 800)->assertJsonPath('rows.0.variance', -200)->assertJsonPath('rows.0.variance_percentage_bps', -2000)->assertJsonPath('rows.0.favorable', true);
    }

    public function test_actual_without_budget_returns_null_percentage_and_revenue_performance(): void
    {
        $context = $this->stage7PlanningContext();
        $budgetId = $this->createBudget($context, 3000);
        app(JournalPostingService::class)->post($context['company']->id, $context['user'], ['posting_date' => '2027-01-15', 'description' => 'Revenue without budget', 'lines' => [['account_id' => $context['accounts']['cash']->id, 'debit' => 500, 'credit' => 0], ['account_id' => $context['accounts']['revenue']->id, 'debit' => 0, 'credit' => 500]]]);

        $this->getJson("/api/v1/planning/budgets/{$budgetId}/actual?from=2027-01-01&to=2027-01-31", $this->headers($context['company']->id))->assertOk()->assertJsonPath('rows.0.account_id', $context['accounts']['revenue']->id)->assertJsonPath('rows.0.budget', 0)->assertJsonPath('rows.0.actual', 500)->assertJsonPath('rows.0.variance_percentage_bps', null)->assertJsonPath('rows.0.favorable', true);
    }

    public function test_comparative_reporting_uses_existing_financial_report_engine(): void
    {
        $context = $this->stage7PlanningContext();
        $posting = app(JournalPostingService::class);
        $posting->post($context['company']->id, $context['user'], ['posting_date' => '2027-01-15', 'description' => 'January revenue', 'lines' => [['account_id' => $context['accounts']['cash']->id, 'debit' => 100, 'credit' => 0], ['account_id' => $context['accounts']['revenue']->id, 'debit' => 0, 'credit' => 100]]]);
        $posting->post($context['company']->id, $context['user'], ['posting_date' => '2027-02-15', 'description' => 'February revenue', 'lines' => [['account_id' => $context['accounts']['cash']->id, 'debit' => 150, 'credit' => 0], ['account_id' => $context['accounts']['revenue']->id, 'debit' => 0, 'credit' => 150]]]);

        $this->getJson('/api/v1/accounting/reports/comparative?from=2027-02-01&to=2027-02-28&comparison_from=2027-01-01&comparison_to=2027-01-31', $this->headers($context['company']->id))->assertOk()->assertJsonPath('current.revenue', 150)->assertJsonPath('comparison.revenue', 100);
    }

    public function test_budget_endpoints_enforce_rbac_and_tenant_isolation(): void
    {
        $context = $this->stage7PlanningContext();
        $budgetId = $this->createBudget($context, 1200);
        [, $otherCompany] = $this->actingAsCompanyUser(['budget.view']);

        $this->getJson("/api/v1/planning/budgets/{$budgetId}", $this->headers($otherCompany->id))->assertNotFound();
        $this->postJson('/api/v1/planning/budgets', ['fiscal_year_id' => $context['fiscalYear']->id, 'name' => 'Denied', 'currency' => 'PKR'], $this->headers($otherCompany->id))->assertForbidden();
    }

    public function test_stage_seven_factories_create_planning_records(): void
    {
        $context = $this->stage7PlanningContext();
        $budget = Budget::factory()->create(['company_id' => $context['company']->id, 'fiscal_year_id' => $context['fiscalYear']->id, 'created_by' => $context['user']->id]);
        $line = BudgetLine::factory()->create(['company_id' => $context['company']->id, 'budget_id' => $budget->id, 'account_id' => $context['accounts']['expense']->id, 'accounting_period_id' => $context['periods'][0]->id]);

        $this->assertSame($budget->id, $line->budget_id);
        $this->assertSame($context['fiscalYear']->id, $budget->fiscal_year_id);
    }

    /** @param array<string, mixed> $context */
    private function createBudget(array $context, int $annual): string
    {
        return (string) $this->postJson('/api/v1/planning/budgets', ['fiscal_year_id' => $context['fiscalYear']->id, 'name' => 'Operating Budget', 'currency' => 'PKR', 'lines' => [['account_id' => $context['accounts']['expense']->id, 'annual_amount' => $annual, 'distribution' => 'equal']]], $this->headers($context['company']->id))->assertCreated()->json('id');
    }

    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId, 'Accept' => 'application/json'];
    }
}
