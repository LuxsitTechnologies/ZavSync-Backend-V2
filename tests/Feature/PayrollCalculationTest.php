<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollComponent;
use App\Models\PayrollEntry;
use App\Models\PayrollStatutoryRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_creation_selects_eligible_employees_and_prevents_duplicates(): void
    {
        $context = $this->stage8PayrollContext();
        $ineligible = Employee::factory()->for($context['company'])->create(['employee_code' => 'EMP-FUTURE', 'joining_date' => '2026-10-01', 'created_by' => $context['user']->id]);
        EmployeePayrollProfile::factory()->for($context['company'])->for($ineligible)->create(['effective_from' => '2026-01-01', 'created_by' => $context['user']->id]);

        $response = $this->createBatch($context)->assertCreated()->assertJsonPath('employee_count', 1)->assertJsonCount(1, 'entries');
        $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $context['payrollPeriod']->id], $this->headers($context['company']->id))->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_BATCH_ALREADY_EXISTS');
        $this->assertDatabaseHas('payroll_entries', ['payroll_batch_id' => $response->json('id'), 'employee_id' => $context['employee']->id]);
        $this->assertDatabaseMissing('payroll_entries', ['payroll_batch_id' => $response->json('id'), 'employee_id' => $ineligible->id]);
    }

    public function test_batch_snapshots_salary_components_and_statutory_versions(): void
    {
        $context = $this->stage8PayrollContext();
        $batchId = $this->createBatch($context)->json('id');
        $context['profile']->update(['base_salary' => 9_000_000]);
        $context['components']['allowance']->update(['fixed_amount' => 900_000]);
        $context['rule']->update(['rate_bps' => 9000]);

        $response = $this->postJson("/api/v1/payroll/batches/{$batchId}/calculate", [], $this->headers($context['company']->id))->assertOk();

        $response->assertJsonPath('entries.0.base_salary', 1_000_000)->assertJsonPath('entries.0.gross_earnings', 1_100_000)->assertJsonPath('entries.0.tax_amount', 110_000);
        $entry = PayrollEntry::query()->where('payroll_batch_id', $batchId)->firstOrFail();
        $this->assertSame('2026-test', $entry->statutory_rule_snapshot[0]['version']);
    }

    public function test_calculation_produces_authoritative_integer_totals(): void
    {
        $context = $this->stage8PayrollContext();
        $batchId = $this->createBatch($context)->json('id');

        $response = $this->postJson("/api/v1/payroll/batches/{$batchId}/calculate", ['gross_earnings' => 1], $this->headers($context['company']->id))->assertOk();

        $response->assertJsonPath('status', 'CALCULATED')
            ->assertJsonPath('gross_earnings', 1_100_000)
            ->assertJsonPath('taxable_earnings', 1_100_000)
            ->assertJsonPath('employee_deductions', 50_000)
            ->assertJsonPath('employee_contributions', 30_000)
            ->assertJsonPath('tax_amount', 110_000)
            ->assertJsonPath('employer_contributions', 40_000)
            ->assertJsonPath('net_pay', 910_000)
            ->assertJsonPath('employer_total_cost', 1_140_000);
        $this->assertIsInt($response->json('net_pay'));
    }

    public function test_basis_point_rounding_is_deterministic_without_floating_point(): void
    {
        $context = $this->stage8PayrollContext();
        $context['profile']->update(['base_salary' => 1_000_002]);
        $context['components']['allowance']->update(['calculation_method' => 'basis_points', 'fixed_amount' => null, 'rate_bps' => 3333]);
        $batchId = $this->createBatch($context)->json('id');

        $first = $this->postJson("/api/v1/payroll/batches/{$batchId}/calculate", [], $this->headers($context['company']->id))->assertOk()->json();
        $second = $this->postJson("/api/v1/payroll/batches/{$batchId}/recalculate", [], $this->headers($context['company']->id))->assertOk()->json();

        $this->assertSame(1_333_303, $first['gross_earnings']);
        $this->assertSame($first['gross_earnings'], $second['gross_earnings']);
        $this->assertSame($first['net_pay'], $second['net_pay']);
    }

    public function test_effective_dated_statutory_rule_excludes_future_versions(): void
    {
        $context = $this->stage8PayrollContext();
        PayrollStatutoryRule::factory()->for($context['company'])->for($context['components']['tax'], 'component')->create(['version' => 'future', 'effective_from' => '2026-10-01', 'rate_bps' => 9000, 'created_by' => $context['user']->id]);
        $batchId = $this->createBatch($context)->json('id');

        $this->postJson("/api/v1/payroll/batches/{$batchId}/calculate", [], $this->headers($context['company']->id))->assertOk()->assertJsonPath('tax_amount', 110_000);
        $entry = PayrollEntry::query()->where('payroll_batch_id', $batchId)->firstOrFail();
        $this->assertSame(['2026-test'], collect($entry->statutory_rule_snapshot)->pluck('version')->all());
    }

    public function test_manual_adjustment_is_audited_and_recalculation_preserves_it(): void
    {
        $context = $this->stage8PayrollContext();
        $batchId = $this->createBatch($context)->json('id');
        $entry = PayrollEntry::query()->where('payroll_batch_id', $batchId)->firstOrFail();
        $bonus = PayrollComponent::factory()->for($context['company'])->create(['code' => 'BONUS', 'name' => 'Bonus', 'type' => 'EARNINGS', 'calculation_method' => 'manual', 'fixed_amount' => null, 'is_taxable' => true, 'gl_account_id' => $context['accounts']['salary_expense']->id, 'created_by' => $context['user']->id]);

        $this->postJson('/api/v1/payroll/entries/'.$entry->id.'/adjustments', ['payroll_component_id' => $bonus->id, 'amount' => 25_000, 'reason' => 'Approved performance bonus'], $this->headers($context['company']->id))->assertCreated();
        $this->postJson("/api/v1/payroll/batches/{$batchId}/calculate", [], $this->headers($context['company']->id))->assertOk()->assertJsonPath('gross_earnings', 1_125_000);
        $this->postJson("/api/v1/payroll/batches/{$batchId}/recalculate", [], $this->headers($context['company']->id))->assertOk()->assertJsonPath('gross_earnings', 1_125_000);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id, 'module' => 'payroll', 'action' => 'manual_adjustment']);
    }

    public function test_cross_company_employee_profile_is_never_selected_for_batch(): void
    {
        $context = $this->stage8PayrollContext();
        $otherCompany = Company::factory()->create();
        $foreignEmployee = Employee::factory()->for($otherCompany)->create(['employee_code' => 'FOREIGN', 'created_by' => $context['user']->id]);
        EmployeePayrollProfile::factory()->for($otherCompany)->for($foreignEmployee)->create(['effective_from' => '2026-01-01', 'created_by' => $context['user']->id]);
        $batchId = $this->createBatch($context)->assertCreated()->assertJsonPath('employee_count', 1)->json('id');
        $this->assertDatabaseMissing('payroll_entries', ['payroll_batch_id' => $batchId, 'employee_id' => $foreignEmployee->id]);
    }

    /** @param array<string, mixed> $context */
    private function createBatch(array $context)
    {
        return $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $context['payrollPeriod']->id], $this->headers($context['company']->id));
    }

    /** @return array<string, string> */
    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId, 'Accept' => 'application/json'];
    }
}
