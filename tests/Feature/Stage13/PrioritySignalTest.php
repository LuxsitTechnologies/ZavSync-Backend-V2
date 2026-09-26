<?php

namespace Tests\Feature\Stage13;

use App\Models\AnomalyResult;
use App\Models\CrmActivity;
use App\Models\OperationalPrioritySignal;
use App\Services\Ai\OperationalSignalService;
use App\Services\Ai\PriorityScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PrioritySignalTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_generates_idempotent_company_scoped_signal_with_score_breakdown(): void
    {
        Queue::fake();
        $context = $this->stage13IntelligenceContext();
        CrmActivity::factory()->for($context['company'])->create(['owner_id' => $context['user']->id, 'created_by' => $context['user']->id, 'status' => 'PENDING', 'due_at' => now()->subDay()]);

        $first = app(OperationalSignalService::class)->refresh($context['company']->id);
        $second = app(OperationalSignalService::class)->refresh($context['company']->id);

        $this->assertGreaterThanOrEqual(1, $first['detected']);
        $this->assertSame($first['fingerprints'], $second['fingerprints']);
        $this->assertSame(1, OperationalPrioritySignal::query()->where('company_id', $context['company']->id)->where('source_module', 'crm')->count());
        $signal = OperationalPrioritySignal::query()->where('company_id', $context['company']->id)->where('source_module', 'crm')->firstOrFail();
        $this->assertIsInt($signal->priority_score);
        $this->assertArrayHasKey('severity', $signal->score_breakdown);
    }

    public function test_priority_score_is_deterministic_and_integer_only(): void
    {
        $factors = ['severity' => 'HIGH', 'amount_minor' => 500000, 'overdue_days' => 45, 'integrity_risk' => true, 'confidence_bps' => 8000];

        $first = app(PriorityScoringService::class)->score($factors);
        $second = app(PriorityScoringService::class)->score($factors);

        $this->assertSame($first, $second);
        $this->assertIsInt($first['score']);
        $this->assertLessThanOrEqual(10000, $first['score']);
    }

    public function test_active_accounting_anomaly_is_exposed_as_an_explainable_priority(): void
    {
        $context = $this->stage13IntelligenceContext();
        $anomaly = AnomalyResult::factory()->for($context['company'])->create(['source_module' => 'accounting', 'status' => 'ACTIVE', 'metric' => 'monthly_expense', 'deviation_bps' => 6000]);

        app(OperationalSignalService::class)->refresh($context['company']->id);

        $signal = OperationalPrioritySignal::query()->where('company_id', $context['company']->id)->where('source_type', 'anomaly_result')->firstOrFail();
        $this->assertSame($anomaly->id, $signal->source_id);
        $this->assertSame('HIGH', $signal->severity);
        $this->assertSame(6000, $signal->supporting_metrics['deviation_bps']);
        $this->assertSame('/ai/analytics', $signal->related_url);
    }

    public function test_missing_condition_is_auto_resolved_with_auditable_history(): void
    {
        $context = $this->stage13IntelligenceContext();
        $signal = OperationalPrioritySignal::factory()->for($context['company'])->create(['source_module' => 'crm', 'explanation_metadata' => ['required_permission' => 'crm.view']]);

        $result = app(OperationalSignalService::class)->refresh($context['company']->id);

        $this->assertGreaterThanOrEqual(1, $result['resolved']);
        $this->assertSame('RESOLVED', $signal->fresh()->status);
        $this->assertDatabaseHas('operational_signal_events', ['operational_priority_signal_id' => $signal->id, 'event_type' => 'AUTO_RESOLVED', 'from_status' => 'OPEN', 'to_status' => 'RESOLVED']);
    }

    public function test_signal_can_be_acknowledged_assigned_resolved_and_reopened_with_history(): void
    {
        $context = $this->stage13IntelligenceContext();
        $signal = OperationalPrioritySignal::factory()->for($context['company'])->create(['explanation_metadata' => ['required_permission' => 'crm.view']]);
        $headers = $this->headers($context['company']->id);

        $this->patchJson("/api/v1/ai/intelligence/priorities/{$signal->id}", ['action' => 'ACKNOWLEDGE'], $headers)->assertOk()->assertJsonPath('status', 'ACKNOWLEDGED');
        $this->patchJson("/api/v1/ai/intelligence/priorities/{$signal->id}", ['action' => 'ASSIGN', 'assigned_user_id' => $context['user']->id], $headers)->assertOk()->assertJsonPath('assigned_user_id', $context['user']->id);
        $this->patchJson("/api/v1/ai/intelligence/priorities/{$signal->id}", ['action' => 'RESOLVE', 'note' => 'Reviewed'], $headers)->assertOk()->assertJsonPath('status', 'RESOLVED');
        $this->patchJson("/api/v1/ai/intelligence/priorities/{$signal->id}", ['action' => 'REOPEN'], $headers)->assertOk()->assertJsonPath('status', 'OPEN');

        $this->assertDatabaseCount('operational_signal_events', 4);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id, 'action' => 'update_priority_signal']);
    }

    public function test_priority_routes_enforce_tenant_and_underlying_source_permission(): void
    {
        $owner = $this->stage13IntelligenceContext();
        $signal = OperationalPrioritySignal::factory()->for($owner['company'])->create(['source_module' => 'payroll', 'explanation_metadata' => ['required_permission' => 'payroll.reports']]);
        $restricted = $this->stage13IntelligenceContext(['intelligence.view']);

        $this->getJson('/api/v1/ai/intelligence/priorities', $this->headers($restricted['company']->id))->assertOk()->assertJsonCount(0, 'signals.data');
        $this->getJson("/api/v1/ai/intelligence/priorities/{$signal->id}", $this->headers($restricted['company']->id))->assertNotFound();
    }

    public function test_signal_transition_requires_manage_permission(): void
    {
        $context = $this->stage13IntelligenceContext(['intelligence.view', 'crm.view']);
        $signal = OperationalPrioritySignal::factory()->for($context['company'])->create(['explanation_metadata' => ['required_permission' => 'crm.view']]);

        $this->patchJson("/api/v1/ai/intelligence/priorities/{$signal->id}", ['action' => 'ACKNOWLEDGE'], $this->headers($context['company']->id))->assertForbidden();
        $this->assertSame('OPEN', $signal->fresh()->status);
    }

    /** @return array<string,string> */
    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId];
    }
}
