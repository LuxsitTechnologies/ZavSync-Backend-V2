<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeePayrollComponent;
use App\Models\EmployeePayrollProfile;
use App\Models\Journal;
use App\Models\PayrollAdjustment;
use App\Models\PayrollBatch;
use App\Models\PayrollComponent;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use App\Models\PayrollLiabilitySettlement;
use App\Models\PayrollLiabilitySettlementAllocation;
use App\Models\PayrollPayment;
use App\Models\PayrollPaymentAllocation;
use App\Models\PayrollPeriod;
use App\Models\PayrollStatutoryRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollSecurityFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_payroll_endpoints_require_authentication_and_company_membership(): void
    {
        $company = Company::factory()->create();
        $this->getJson('/api/v1/payroll/employees', $this->headers($company->id))->assertUnauthorized();

        [$user, $memberCompany] = $this->actingAsCompanyUser(['payroll.view']);
        $this->assertNotNull($user->id);
        $this->getJson('/api/v1/payroll/employees', $this->headers($memberCompany->id))->assertOk();
        $this->getJson('/api/v1/payroll/employees', $this->headers($company->id))->assertForbidden();
    }

    public function test_tenant_scoping_hides_employees_batches_entries_and_reports(): void
    {
        $context = $this->stage8PayrollContext();
        $other = $this->stage8PayrollContext();
        $otherBatchId = $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $other['payrollPeriod']->id], $this->headers($other['company']->id))->assertCreated()->json('id');
        $this->postJson("/api/v1/payroll/batches/{$otherBatchId}/calculate", [], $this->headers($other['company']->id))->assertOk();
        $otherEntry = PayrollEntry::query()->where('payroll_batch_id', $otherBatchId)->firstOrFail();

        $this->actingAs($context['user']);
        $this->getJson("/api/v1/payroll/employees/{$other['employee']->id}", $this->headers($context['company']->id))->assertNotFound();
        $this->getJson("/api/v1/payroll/batches/{$otherBatchId}", $this->headers($context['company']->id))->assertNotFound();
        $this->getJson("/api/v1/payroll/entries/{$otherEntry->id}", $this->headers($context['company']->id))->assertNotFound();
        $this->getJson("/api/v1/payroll/reports/register/{$otherBatchId}", $this->headers($context['company']->id))->assertNotFound();
        $this->getJson("/api/v1/payroll/reports/employees/{$other['employee']->id}", $this->headers($context['company']->id))->assertNotFound();
    }

    public function test_payload_company_fields_cannot_override_resolved_tenant(): void
    {
        $context = $this->stage8PayrollContext();
        $foreign = Company::factory()->create();

        $response = $this->postJson('/api/v1/payroll/employees', ['company_id' => $foreign->id, 'employee_code' => 'EMP-TENANT', 'full_name' => 'Scoped Employee', 'employment_type' => 'full_time', 'status' => 'active', 'joining_date' => '2026-09-01'], $this->headers($context['company']->id))->assertCreated();
        $this->assertSame($context['company']->id, $response->json('company_id'));
        $this->assertDatabaseHas('employees', ['id' => $response->json('id'), 'company_id' => $context['company']->id]);
        $this->assertDatabaseMissing('employees', ['id' => $response->json('id'), 'company_id' => $foreign->id]);
    }

    public function test_role_permissions_separate_view_configuration_calculation_approval_posting_and_payment(): void
    {
        $context = $this->stage8PayrollContext(['payroll.view']);
        $this->getJson('/api/v1/payroll/employees', $this->headers($context['company']->id))->assertOk();
        $this->postJson('/api/v1/payroll/employees', ['employee_code' => 'DENIED', 'full_name' => 'Denied', 'employment_type' => 'full_time', 'status' => 'active', 'joining_date' => '2026-09-01'], $this->headers($context['company']->id))->assertForbidden();
        $this->postJson('/api/v1/payroll/components', ['code' => 'DENIED', 'name' => 'Denied', 'type' => 'EARNINGS', 'calculation_method' => 'fixed', 'fixed_amount' => 1], $this->headers($context['company']->id))->assertForbidden();
        $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $context['payrollPeriod']->id], $this->headers($context['company']->id))->assertForbidden();
        $this->getJson('/api/v1/payroll/reports/summary', $this->headers($context['company']->id))->assertForbidden();
    }

    public function test_repeated_state_transitions_do_not_bypass_lifecycle_guards(): void
    {
        $context = $this->stage8PayrollContext();
        $batchId = $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $context['payrollPeriod']->id], $this->headers($context['company']->id))->assertCreated()->json('id');
        $this->postJson("/api/v1/payroll/batches/{$batchId}/calculate", [], $this->headers($context['company']->id))->assertOk();
        $this->postJson("/api/v1/payroll/batches/{$batchId}/review", [], $this->headers($context['company']->id))->assertOk();

        $this->postJson("/api/v1/payroll/batches/{$batchId}/review", [], $this->headers($context['company']->id))->assertUnprocessable();
        $this->postJson("/api/v1/payroll/batches/{$batchId}/calculate", [], $this->headers($context['company']->id))->assertUnprocessable();
        $this->assertDatabaseHas('payroll_batches', ['id' => $batchId, 'status' => 'REVIEWED']);
    }

    public function test_stage8_factories_create_all_payroll_domain_models_with_valid_relations(): void
    {
        $context = $this->stage8PayrollContext();
        $employee = Employee::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        $component = PayrollComponent::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        $profile = EmployeePayrollProfile::factory()->for($context['company'])->for($employee)->create(['created_by' => $context['user']->id]);
        $assignment = EmployeePayrollComponent::factory()->for($context['company'])->for($profile, 'profile')->for($component, 'component')->create();
        $rule = PayrollStatutoryRule::factory()->for($context['company'])->for($component, 'component')->create(['created_by' => $context['user']->id]);
        $period = PayrollPeriod::factory()->for($context['company'])->create(['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-10-31', 'created_by' => $context['user']->id]);
        $batch = PayrollBatch::factory()->for($context['company'])->for($period, 'period')->create(['created_by' => $context['user']->id]);
        $entry = PayrollEntry::factory()->for($context['company'])->for($batch, 'batch')->for($employee)->for($profile, 'profile')->create();
        $adjustment = PayrollAdjustment::factory()->for($context['company'])->for($entry, 'entry')->for($component, 'component')->create(['created_by' => $context['user']->id]);
        $line = PayrollEntryLine::factory()->for($context['company'])->for($entry, 'entry')->create(['payroll_component_id' => $component->id, 'payroll_adjustment_id' => $adjustment->id]);
        $paymentJournal = Journal::factory()->for($context['company'])->create(['created_by' => $context['user']->id, 'posted_by' => $context['user']->id]);
        $payment = PayrollPayment::factory()->for($context['company'])->for($batch, 'batch')->for($context['bank'], 'financialAccount')->for($paymentJournal, 'journal')->create(['created_by' => $context['user']->id]);
        $paymentAllocation = PayrollPaymentAllocation::factory()->for($context['company'])->for($payment, 'payment')->for($entry, 'entry')->create();
        $settlementJournal = Journal::factory()->for($context['company'])->create(['created_by' => $context['user']->id, 'posted_by' => $context['user']->id]);
        $settlement = PayrollLiabilitySettlement::factory()->for($context['company'])->for($batch, 'batch')->for($context['bank'], 'financialAccount')->for($settlementJournal, 'journal')->create(['created_by' => $context['user']->id]);
        $settlementAllocation = PayrollLiabilitySettlementAllocation::factory()->for($context['company'])->for($settlement, 'settlement')->for($line, 'entryLine')->create();

        $this->assertSame($context['company']->id, $assignment->company_id);
        $this->assertSame($component->id, $rule->payroll_component_id);
        $this->assertSame($period->id, $batch->payroll_period_id);
        $this->assertSame($entry->id, $line->payroll_entry_id);
        $this->assertSame($payment->id, $paymentAllocation->payroll_payment_id);
        $this->assertSame($settlement->id, $settlementAllocation->payroll_liability_settlement_id);
        $this->assertDatabaseCount('employees', 2);
    }

    public function test_material_payroll_actions_write_audit_history(): void
    {
        $context = $this->stage8PayrollContext();
        $employee = $this->postJson('/api/v1/payroll/employees', ['employee_code' => 'EMP-AUDIT', 'full_name' => 'Audit Employee', 'employment_type' => 'full_time', 'status' => 'active', 'joining_date' => '2026-09-01'], $this->headers($context['company']->id))->assertCreated();
        $batchId = $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $context['payrollPeriod']->id], $this->headers($context['company']->id))->assertCreated()->json('id');
        $this->postJson("/api/v1/payroll/batches/{$batchId}/calculate", [], $this->headers($context['company']->id))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id, 'module' => 'payroll_employee', 'action' => 'create', 'entity_id' => $employee->json('id')]);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id, 'module' => 'payroll', 'action' => 'create_batch', 'entity_id' => $batchId]);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id, 'module' => 'payroll', 'action' => 'calculate', 'entity_id' => $batchId]);
    }

    /** @return array<string, string> */
    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId, 'Accept' => 'application/json'];
    }
}
