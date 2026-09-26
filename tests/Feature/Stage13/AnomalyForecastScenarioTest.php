<?php

namespace Tests\Feature\Stage13;

use App\Models\Account;
use App\Models\AnomalyResult;
use App\Models\FinancialAccount;
use App\Models\IntelligenceForecast;
use App\Models\IntelligenceScenario;
use App\Services\Ai\AnomalyDetectionService;
use App\Services\Ai\ForecastIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnomalyForecastScenarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_anomaly_calculation_is_explainable_and_deterministic(): void
    {
        $service = app(AnomalyDetectionService::class);

        $result = $service->evaluate([90000, 100000, 110000, 150000], 2000);

        $this->assertTrue($result['is_anomaly']);
        $this->assertSame(100000, $result['expected']);
        $this->assertSame(150000, $result['observed']);
        $this->assertSame(50000, $result['deviation']);
        $this->assertSame(5000, $result['deviation_bps']);
        $this->assertSame(4, $result['sample_size']);
    }

    public function test_anomaly_calculation_returns_insufficient_result_for_short_history(): void
    {
        $result = app(AnomalyDetectionService::class)->evaluate([100, 120, 130], 2000);

        $this->assertFalse($result['is_anomaly']);
        $this->assertNull($result['deviation_bps']);
        $this->assertSame(3, $result['sample_size']);
    }

    public function test_forecast_explicitly_persists_insufficient_data_without_cash_history(): void
    {
        $context = $this->stage13IntelligenceContext();

        $forecast = app(ForecastIntelligenceService::class)->cash($context['company']->id);

        $this->assertSame('INSUFFICIENT_DATA', $forecast->status);
        $this->assertSame([], $forecast->projection_points);
        $this->assertNull($forecast->confidence_bps);
    }

    public function test_cash_forecast_reuses_authoritative_banking_forecast_with_integer_projection(): void
    {
        $context = $this->stage13IntelligenceContext();
        $account = Account::factory()->for($context['company'])->create(['opening_balance' => 1000000, 'created_by' => $context['user']->id]);
        FinancialAccount::factory()->for($context['company'])->create(['gl_account_id' => $account->id, 'currency' => 'PKR', 'created_by' => $context['user']->id]);

        $forecast = app(ForecastIntelligenceService::class)->cash($context['company']->id);

        $this->assertSame('READY', $forecast->status);
        $this->assertSame('AUTHORITATIVE_CASH_FORECAST', $forecast->method);
        $this->assertSame(1000000, $forecast->projection_points[0]['value_minor']);
        $this->assertIsInt($forecast->projection_points[0]['value_minor']);
    }

    public function test_scenario_uses_integer_values_is_idempotent_and_does_not_mutate_financial_data(): void
    {
        $context = $this->stage13IntelligenceContext();
        $account = Account::factory()->for($context['company'])->create(['opening_balance' => 1000000, 'created_by' => $context['user']->id]);
        FinancialAccount::factory()->for($context['company'])->create(['gl_account_id' => $account->id, 'currency' => 'PKR', 'created_by' => $context['user']->id]);
        $payload = ['name' => 'Revenue decline', 'scenario_type' => 'REVENUE_CHANGE', 'assumptions' => ['change_bps' => -1000]];
        $headers = $this->headers($context['company']->id, 'scenario-1');

        $first = $this->postJson('/api/v1/ai/intelligence/scenarios', $payload, $headers)->assertCreated()->assertJsonPath('baseline.projected_cash_minor', 1000000)->assertJsonPath('scenario.projected_cash_minor', 1000000);
        $second = $this->postJson('/api/v1/ai/intelligence/scenarios', $payload, $headers)->assertCreated();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('intelligence_scenarios', 1);
        $this->assertDatabaseCount('journals', 0);
        $this->assertIsInt(IntelligenceScenario::query()->firstOrFail()->baseline['projected_cash_minor']);
    }

    public function test_scenario_conflicting_idempotency_key_returns_conflict(): void
    {
        $context = $this->stage13IntelligenceContext();
        $account = Account::factory()->for($context['company'])->create(['opening_balance' => 1000000, 'created_by' => $context['user']->id]);
        FinancialAccount::factory()->for($context['company'])->create(['gl_account_id' => $account->id, 'created_by' => $context['user']->id]);
        $headers = $this->headers($context['company']->id, 'same-key');
        $this->postJson('/api/v1/ai/intelligence/scenarios', ['name' => 'First', 'scenario_type' => 'EXPENSE_CHANGE', 'assumptions' => ['change_bps' => 500]], $headers)->assertCreated();

        $this->postJson('/api/v1/ai/intelligence/scenarios', ['name' => 'Second', 'scenario_type' => 'EXPENSE_CHANGE', 'assumptions' => ['change_bps' => 800]], $headers)->assertConflict();
        $this->assertDatabaseCount('intelligence_scenarios', 1);
    }

    public function test_scenario_requires_the_assumption_used_by_its_selected_method(): void
    {
        $context = $this->stage13IntelligenceContext();

        $this->postJson('/api/v1/ai/intelligence/scenarios', ['name' => 'Invalid', 'scenario_type' => 'CUSTOMER_NON_PAYMENT', 'assumptions' => []], $this->headers($context['company']->id, 'invalid-scenario'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assumptions.amount_minor');

        $this->assertDatabaseCount('intelligence_scenarios', 0);
    }

    public function test_anomaly_and_forecast_endpoints_hide_unauthorized_source_modules(): void
    {
        $context = $this->stage13IntelligenceContext(['intelligence.anomalies.view', 'intelligence.forecasts.view']);
        AnomalyResult::factory()->for($context['company'])->create(['source_module' => 'payroll']);
        IntelligenceForecast::factory()->for($context['company'])->create(['source_module' => 'banking']);

        $this->getJson('/api/v1/ai/intelligence/anomalies', $this->headers($context['company']->id))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/ai/intelligence/forecasts', $this->headers($context['company']->id))->assertOk()->assertJsonCount(0, 'data');
    }

    /** @return array<string,string> */
    private function headers(string $companyId, ?string $idempotencyKey = null): array
    {
        return array_filter(['X-Company-Id' => $companyId, 'Idempotency-Key' => $idempotencyKey]);
    }
}
