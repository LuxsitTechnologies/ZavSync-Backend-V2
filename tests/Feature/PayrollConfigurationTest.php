<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_and_profile_endpoints_are_company_scoped(): void
    {
        $context = $this->stage8PayrollContext();
        [, $otherCompany] = $this->actingAsCompanyUser(['payroll.view', 'payroll.configure']);

        $this->getJson('/api/v1/payroll/employees/'.$context['employee']->id, $this->headers($otherCompany->id))->assertNotFound();
        $this->getJson('/api/v1/payroll/employees/'.$context['employee']->id.'/profiles', $this->headers($otherCompany->id))->assertNotFound();
        $this->getJson('/api/v1/payroll/employees', $this->headers($otherCompany->id))->assertOk()->assertJsonCount(0);
    }

    public function test_employee_creation_ignores_company_input_and_validates_dates_and_uniqueness(): void
    {
        $context = $this->stage8PayrollContext();
        $foreign = Company::factory()->create();
        $payload = ['company_id' => $foreign->id, 'employee_code' => 'EMP-0002', 'full_name' => 'Second Employee', 'email' => 'second@example.com', 'employment_type' => 'full_time', 'status' => 'active', 'joining_date' => '2026-09-10', 'leaving_date' => '2026-09-01'];

        $this->postJson('/api/v1/payroll/employees', $payload, $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors('leaving_date');
        $payload['leaving_date'] = null;
        $response = $this->postJson('/api/v1/payroll/employees', $payload, $this->headers($context['company']->id))->assertCreated()->assertJsonPath('company_id', $context['company']->id);
        $this->assertDatabaseHas('employees', ['id' => $response->json('id'), 'company_id' => $context['company']->id]);
        $this->postJson('/api/v1/payroll/employees', $payload, $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors(['employee_code', 'email']);
    }

    public function test_component_configuration_rejects_cross_company_accounts_and_enforces_rbac(): void
    {
        $context = $this->stage8PayrollContext();
        $foreignCompany = Company::factory()->create();
        $foreignAccount = Account::factory()->for($foreignCompany)->expense()->create(['created_by' => $context['user']->id]);
        $payload = ['code' => 'BONUS', 'name' => 'Bonus', 'type' => 'EARNINGS', 'calculation_method' => 'fixed', 'fixed_amount' => 50_000, 'is_taxable' => true, 'gl_account_id' => $foreignAccount->id];

        $this->postJson('/api/v1/payroll/components', $payload, $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors('gl_account_id');
        [, $viewOnlyCompany] = $this->actingAsCompanyUser(['payroll.view']);
        $this->postJson('/api/v1/payroll/components', $payload, $this->headers($viewOnlyCompany->id))->assertForbidden();
    }

    public function test_component_deactivation_preserves_historical_records(): void
    {
        $context = $this->stage8PayrollContext();

        $this->patchJson('/api/v1/payroll/components/'.$context['components']['allowance']->id.'/deactivate', [], $this->headers($context['company']->id))->assertOk()->assertJsonPath('is_active', false);
        $this->assertDatabaseHas('payroll_components', ['id' => $context['components']['allowance']->id, 'is_active' => false]);
    }

    public function test_profile_versions_are_effective_dated_and_do_not_overwrite_history(): void
    {
        $context = $this->stage8PayrollContext();
        $payload = ['payroll_status' => 'active', 'pay_frequency' => 'monthly', 'base_salary' => 1_200_000, 'currency' => 'PKR', 'effective_from' => '2026-10-01', 'payment_financial_account_id' => $context['bank']->id, 'components' => []];

        $response = $this->postJson('/api/v1/payroll/employees/'.$context['employee']->id.'/profiles', $payload, $this->headers($context['company']->id))->assertCreated()->assertJsonPath('base_salary', 1_200_000);
        $this->assertNotSame($context['profile']->id, $response->json('id'));
        $this->assertSame('2026-09-30', EmployeePayrollProfile::query()->findOrFail($context['profile']->id)->effective_to->format('Y-m-d'));
    }

    public function test_profile_rejects_cross_company_components_and_financial_accounts(): void
    {
        $context = $this->stage8PayrollContext();
        $foreign = Company::factory()->create();
        $foreignComponent = PayrollComponent::factory()->for($foreign)->create(['created_by' => $context['user']->id]);
        $payload = ['payroll_status' => 'active', 'pay_frequency' => 'monthly', 'base_salary' => 1_000_000, 'currency' => 'PKR', 'effective_from' => '2026-10-01', 'components' => [['payroll_component_id' => $foreignComponent->id]]];

        $this->postJson('/api/v1/payroll/employees/'.$context['employee']->id.'/profiles', $payload, $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors('components.0.payroll_component_id');
    }

    public function test_payroll_period_rejects_invalid_and_overlapping_ranges(): void
    {
        $context = $this->stage8PayrollContext();
        $invalid = ['name' => 'Invalid', 'frequency' => 'monthly', 'period_start' => '2026-10-31', 'period_end' => '2026-10-01', 'pay_date' => '2026-10-31'];

        $this->postJson('/api/v1/payroll/periods', $invalid, $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors('period_end');
        $overlap = ['name' => 'Overlap', 'frequency' => 'monthly', 'period_start' => '2026-09-15', 'period_end' => '2026-10-15', 'pay_date' => '2026-10-15'];
        $this->postJson('/api/v1/payroll/periods', $overlap, $this->headers($context['company']->id))->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_PERIOD_INVALID');
    }

    public function test_statutory_rules_are_company_scoped_and_effective_dated(): void
    {
        $context = $this->stage8PayrollContext();
        $payload = ['payroll_component_id' => $context['components']['tax']->id, 'jurisdiction' => 'PK-TEST', 'rule_type' => 'INCOME_TAX', 'version' => '2027-test', 'effective_from' => '2027-01-01', 'threshold_from' => 500_000, 'rate_bps' => 750, 'fixed_amount' => 0, 'is_active' => true];

        $this->postJson('/api/v1/payroll/statutory-rules', $payload, $this->headers($context['company']->id))->assertCreated()->assertJsonPath('data.version', '2027-test');
        [, $otherCompany] = $this->actingAsCompanyUser(['payroll.view']);
        $this->getJson('/api/v1/payroll/statutory-rules', $this->headers($otherCompany->id))->assertOk()->assertJsonCount(0, 'data');
    }

    /** @return array<string, string> */
    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId, 'Accept' => 'application/json'];
    }
}
