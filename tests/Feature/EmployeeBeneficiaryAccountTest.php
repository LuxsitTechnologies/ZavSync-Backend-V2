<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeBeneficiaryAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeBeneficiaryAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_payroll_administrator_stores_encrypted_account_and_reads_only_masked_details(): void
    {
        $context = $this->stage8PayrollContext();
        $company = $context['company'];
        $employee = $context['employee'];
        $path = "/api/v1/payroll/employees/{$employee->id}/beneficiary-account";
        $before = $this->financialCounts();

        $response = $this->postJson($path, $this->payload(), $this->headers($company->id))->assertCreated()
            ->assertJsonPath('masked_account_number', '••••1234')->assertJsonPath('swift_configured', true)
            ->assertJsonPath('version', 1);
        $this->assertArrayNotHasKey('account_number', $response->json());
        $this->assertArrayNotHasKey('swift_code', $response->json());
        $raw = DB::table('employee_beneficiary_accounts')->where('id', $response->json('id'))->first();
        $this->assertNotSame('PK00-TEST-1234', $raw->account_number);
        $this->assertNotSame('Example Bank', $raw->bank_name);
        $this->assertSame('PK00-TEST-1234', EmployeeBeneficiaryAccount::query()->findOrFail($response->json('id'))->account_number);
        $this->assertStringNotContainsString('PK00-TEST-1234', $response->getContent());
        $this->getJson($path, $this->headers($company->id))->assertOk()->assertJsonPath('id', $response->json('id'))
            ->assertJsonPath('version', $response->json('version'));
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'employee_beneficiary_created']);
        $this->assertSame($before, $this->financialCounts());
    }

    public function test_payroll_administrator_can_update_and_remove_without_secret_audit_or_financial_effects(): void
    {
        $context = $this->stage8PayrollContext();
        $company = $context['company'];
        $employee = $context['employee'];
        $path = "/api/v1/payroll/employees/{$employee->id}/beneficiary-account";
        $created = $this->postJson($path, $this->payload(), $this->headers($company->id))->assertCreated();
        $id = $created->json('id');
        $before = $this->financialCounts();

        $replacement = [...$this->payload(), 'account_number' => 'PK99-NEW-5678', 'version' => $created->json('version')];
        $this->putJson("{$path}/{$id}", $replacement, $this->headers($company->id))->assertOk()
            ->assertJsonPath('masked_account_number', '••••5678')->assertJsonPath('version', 2);
        $this->putJson("{$path}/{$id}", $replacement, $this->headers($company->id))->assertConflict()
            ->assertJsonPath('error_code', 'BENEFICIARY_ACCOUNT_VERSION_STALE');
        $this->deleteJson("{$path}/{$id}", ['version' => 1], $this->headers($company->id))->assertConflict();
        $this->deleteJson("{$path}/{$id}", ['version' => 2], $this->headers($company->id))->assertOk()
            ->assertJsonPath('status', 'REMOVED');
        $this->getJson($path, $this->headers($company->id))->assertNotFound();
        $this->assertDatabaseMissing('employee_beneficiary_accounts', ['id' => $id]);
        $audit = DB::table('audit_logs')->where('action', 'employee_beneficiary_removed')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('PK99-NEW-5678', json_encode($audit, JSON_THROW_ON_ERROR));
        $this->assertSame($before, $this->financialCounts());
    }

    public function test_beneficiary_endpoints_enforce_permission_and_company_isolation(): void
    {
        $context = $this->stage8PayrollContext();
        $company = $context['company'];
        $employee = $context['employee'];
        $path = "/api/v1/payroll/employees/{$employee->id}/beneficiary-account";
        $this->postJson($path, $this->payload(), $this->headers($company->id))->assertCreated();

        [, $otherCompany] = $this->actingAsCompanyUser(['payroll.configure']);
        $this->getJson($path, $this->headers($otherCompany->id))->assertNotFound();
        $this->postJson($path, $this->payload(), $this->headers($otherCompany->id))->assertNotFound();
        $foreignEmployee = Employee::factory()->for(Company::factory())->create();
        $this->getJson("/api/v1/payroll/employees/{$foreignEmployee->id}/beneficiary-account", $this->headers($company->id))->assertForbidden();

        [, $viewOnlyCompany] = $this->actingAsCompanyUser(['payroll.view']);
        $localEmployee = Employee::factory()->for($viewOnlyCompany)->create();
        $viewPath = "/api/v1/payroll/employees/{$localEmployee->id}/beneficiary-account";
        $this->getJson($viewPath, $this->headers($viewOnlyCompany->id))->assertForbidden();
        $this->postJson($viewPath, $this->payload(), $this->headers($viewOnlyCompany->id))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson($path, $this->headers($company->id))->assertUnauthorized();
    }

    public function test_beneficiary_validation_rejects_missing_v1_fields_and_duplicate_creation(): void
    {
        $context = $this->stage8PayrollContext();
        $path = "/api/v1/payroll/employees/{$context['employee']->id}/beneficiary-account";
        $headers = $this->headers($context['company']->id);
        $this->postJson($path, [], $headers)->assertUnprocessable()
            ->assertJsonValidationErrors(['account_holder_name', 'account_number', 'bank_name', 'branch_name']);
        $this->postJson($path, [...$this->payload(), 'account_number' => 'x'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('account_number');
        $this->postJson($path, [...$this->payload(), 'account_number' => '----'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('account_number');
        $this->postJson($path, $this->payload(), $headers)->assertCreated();
        $this->postJson($path, $this->payload(), $headers)->assertConflict()
            ->assertJsonPath('error_code', 'BENEFICIARY_ACCOUNT_EXISTS');
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return ['account_holder_name' => 'Payroll Employee', 'account_number' => 'PK00-TEST-1234',
            'bank_name' => 'Example Bank', 'branch_name' => 'Main Branch', 'swift_code' => 'EXAMPKKA'];
    }

    /** @return array<string, string> */
    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId];
    }

    /** @return array<string, int> */
    private function financialCounts(): array
    {
        return collect(['payroll_entries', 'journals', 'journal_lines', 'bank_transactions', 'customer_payments'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
