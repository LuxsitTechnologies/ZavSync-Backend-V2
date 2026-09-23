<?php

namespace Tests\Feature\Accounting;

use App\Models\Budget;
use App\Models\Forecast;
use App\Models\ForecastLine;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_forecast_can_copy_budget_and_has_no_gl_effect(): void
    {
        $context = $this->stage7PlanningContext();
        $budget = $this->budget($context);

        $response = $this->postJson('/api/v1/planning/forecasts', ['fiscal_year_id' => $context['fiscalYear']->id, 'name' => 'Rolling Forecast', 'currency' => 'PKR', 'based_on_budget_id' => $budget->id, 'actuals_through' => '2027-01-31'], $this->headers($context['company']->id));

        $response->assertCreated()->assertJsonPath('status', 'draft')->assertJsonCount(3, 'lines');
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_projection_combines_completed_actuals_with_remaining_forecast(): void
    {
        $context = $this->stage7PlanningContext();
        $budget = $this->budget($context);
        $forecastId = $this->postJson('/api/v1/planning/forecasts', ['fiscal_year_id' => $context['fiscalYear']->id, 'name' => 'Rolling Forecast', 'currency' => 'PKR', 'based_on_budget_id' => $budget->id, 'actuals_through' => '2027-01-31'], $this->headers($context['company']->id))->assertCreated()->json('id');
        app(JournalPostingService::class)->post($context['company']->id, $context['user'], ['posting_date' => '2027-01-15', 'description' => 'Actual expense', 'lines' => [['account_id' => $context['accounts']['expense']->id, 'debit' => 80, 'credit' => 0], ['account_id' => $context['accounts']['cash']->id, 'debit' => 0, 'credit' => 80]]]);

        $this->getJson("/api/v1/planning/forecasts/{$forecastId}/projection?as_of=2027-01-31", $this->headers($context['company']->id))->assertOk()->assertJsonPath('rows.0.actual_completed', 80)->assertJsonPath('rows.0.forecast_completed', 100)->assertJsonPath('rows.0.remaining_forecast', 200)->assertJsonPath('rows.0.full_year_projection', 280)->assertJsonPath('rows.0.variance_percentage_bps', -2000);
    }

    public function test_forecast_versions_preserve_prior_history_and_activation_is_controlled(): void
    {
        $context = $this->stage7PlanningContext();
        $budget = $this->budget($context);
        $headers = $this->headers($context['company']->id);
        $first = $this->postJson('/api/v1/planning/forecasts', ['fiscal_year_id' => $context['fiscalYear']->id, 'name' => 'Rolling Forecast', 'currency' => 'PKR', 'based_on_budget_id' => $budget->id], $headers)->assertCreated();
        $this->postJson('/api/v1/planning/forecasts/'.$first->json('id').'/activate', [], $headers)->assertOk()->assertJsonPath('status', 'active');
        $second = $this->postJson('/api/v1/planning/forecasts', ['fiscal_year_id' => $context['fiscalYear']->id, 'name' => 'Rolling Forecast', 'currency' => 'PKR', 'based_on_forecast_id' => $first->json('id')], $headers)->assertCreated()->assertJsonPath('version', 2);

        $this->assertDatabaseHas('forecasts', ['id' => $first->json('id'), 'status' => 'active']);
        $this->assertDatabaseHas('forecasts', ['id' => $second->json('id'), 'based_on_forecast_id' => $first->json('id')]);
    }

    public function test_forecast_factories_are_valid(): void
    {
        $context = $this->stage7PlanningContext();
        $forecast = Forecast::factory()->create(['company_id' => $context['company']->id, 'fiscal_year_id' => $context['fiscalYear']->id, 'created_by' => $context['user']->id]);
        $line = ForecastLine::factory()->create(['company_id' => $context['company']->id, 'forecast_id' => $forecast->id, 'account_id' => $context['accounts']['revenue']->id, 'accounting_period_id' => $context['periods'][0]->id]);

        $this->assertSame($forecast->id, $line->forecast_id);
    }

    /** @param array<string, mixed> $context */
    private function budget(array $context): Budget
    {
        $budget = Budget::factory()->create(['company_id' => $context['company']->id, 'fiscal_year_id' => $context['fiscalYear']->id, 'created_by' => $context['user']->id]);
        foreach ($context['periods'] as $period) {
            $budget->lines()->create(['company_id' => $context['company']->id, 'account_id' => $context['accounts']['expense']->id, 'accounting_period_id' => $period->id, 'amount' => 100]);
        }

        return $budget;
    }

    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId, 'Accept' => 'application/json'];
    }
}
