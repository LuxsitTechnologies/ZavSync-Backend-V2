<?php

namespace Tests\Feature\Stage16;

use App\Models\AuditLog;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\Permission;
use App\Models\PlatformModule;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeePayrollSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_employee_reads_only_released_history_and_safe_payslip_detail(): void
    {
        $context = $this->releasedContext();
        $entry = $context['entry'];
        $this->actAsEmployee($context);

        $history = $this->getJson('/api/v1/employee/payroll', $this->headers($context['company']))->assertOk();
        $detail = $this->getJson('/api/v1/employee/payroll/'.$entry->id, $this->headers($context['company']))->assertOk();

        $history->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $entry->id);
        $detail->assertJsonPath('id', $entry->id)
            ->assertJsonPath('gross_earnings', 1_100_000)
            ->assertJsonPath('employee_deductions', 50_000)
            ->assertJsonPath('tax_amount', 110_000)
            ->assertJsonPath('net_pay', 910_000)
            ->assertJsonPath('paid_amount', 0)
            ->assertJsonPath('payment_status', 'UNPAID')
            ->assertJsonPath('earnings_lines.0.component_code', 'BASIC');
        $this->assertSame(
            ['id', 'released_at', 'company', 'employee', 'payroll', 'currency', 'base_salary', 'gross_earnings', 'employee_deductions', 'employee_contributions', 'tax_amount', 'reimbursements', 'net_pay', 'paid_amount', 'outstanding_amount', 'payment_status', 'earnings_lines', 'deduction_lines'],
            array_keys($detail->json()),
        );
        $this->assertSame(['id', 'released_at', 'company', 'employee', 'payroll', 'currency', 'base_salary', 'gross_earnings', 'employee_deductions', 'employee_contributions', 'tax_amount', 'reimbursements', 'net_pay', 'paid_amount', 'outstanding_amount', 'payment_status'], array_keys($history->json('data.0')));
        foreach (['profile_snapshot', 'statutory_rule_snapshot', 'gl_account_id', 'liability_account_id', 'employee_bank_reference', 'payment_financial_account_id', 'journal_id', 'correction_reason', 'created_by'] as $privateField) {
            $this->assertStringNotContainsString($privateField, $detail->getContent());
        }
    }

    public function test_posted_but_unreleased_and_work_in_progress_are_invisible(): void
    {
        $context = $this->stage8PayrollContext();
        $batchId = $this->createBatch($context);
        $entry = PayrollEntry::query()->where('payroll_batch_id', $batchId)->firstOrFail();
        $employeeUser = $this->actAsEmployee($context);

        foreach (['DRAFT', 'CALCULATED', 'REVIEWED', 'APPROVED', 'POSTED'] as $status) {
            if ($status !== 'DRAFT') {
                Sanctum::actingAs($context['user']);
                $action = ['CALCULATED' => 'calculate', 'REVIEWED' => 'review', 'APPROVED' => 'approve', 'POSTED' => 'post'][$status];
                $this->postJson("/api/v1/payroll/batches/{$batchId}/{$action}", [], $this->headers($context['company'], 'post-'.$batchId))->assertOk();
                Sanctum::actingAs($employeeUser);
            }
            $this->getJson('/api/v1/employee/payroll', $this->headers($context['company']))->assertOk()->assertJsonPath('meta.total', 0);
            $this->getJson('/api/v1/employee/payroll/'.$entry->id, $this->headers($context['company']))->assertNotFound();
        }
    }

    public function test_returns_409_for_unlinked_membership_without_matching_email_or_code(): void
    {
        $context = $this->stage8PayrollContext();
        $user = User::factory()->create(['email' => $context['employee']->email]);
        $this->membership($context['company'], $user, ['employee.payroll.view']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/employee/payroll', $this->headers($context['company']))
            ->assertStatus(409)->assertJsonPath('error_code', 'EMPLOYEE_IDENTITY_NOT_LINKED');
    }

    public function test_returns_403_without_self_permission_even_if_employee_linked_or_payroll_view_granted(): void
    {
        $context = $this->releasedContext();
        $user = User::factory()->create();
        $this->membership($context['company'], $user, ['payroll.view'], $context['employee']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/employee/payroll', $this->headers($context['company']))->assertForbidden();
        $this->getJson('/api/v1/employee/payroll/'.$context['entry']->id, $this->headers($context['company']))->assertForbidden();
    }

    public function test_self_permission_does_not_grant_payroll_administration_or_release(): void
    {
        $context = $this->releasedContext();
        $this->actAsEmployee($context);

        $this->getJson('/api/v1/payroll/batches', $this->headers($context['company']))->assertForbidden();
        $this->getJson('/api/v1/payroll/entries/'.$context['entry']->id.'/payslip', $this->headers($context['company']))->assertForbidden();
        $this->postJson('/api/v1/payroll/entries/'.$context['entry']->id.'/release', [], $this->headers($context['company']))->assertForbidden();
    }

    public function test_returns_404_for_another_employee_foreign_company_and_malformed_entry(): void
    {
        $context = $this->releasedContext();
        $other = Employee::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        $otherEntry = PayrollEntry::factory()->for($context['company'])->for($context['batch'], 'batch')->for($other)->for($context['profile'], 'profile')->create(['released_at' => now(), 'released_by' => $context['user']->id]);
        $this->actAsEmployee($context);

        $this->getJson('/api/v1/employee/payroll/'.$otherEntry->id, $this->headers($context['company']))->assertNotFound();
        $this->getJson('/api/v1/employee/payroll/not-a-uuid', $this->headers($context['company']))->assertNotFound();
        $foreign = Company::factory()->create();
        $this->getJson('/api/v1/employee/payroll/'.$context['entry']->id, $this->headers($foreign))->assertForbidden();
        $this->getJson('/api/v1/employee/payroll', $this->headers($context['company']))->assertJsonPath('meta.total', 1);
    }

    public function test_company_switching_never_reuses_another_memberships_employee_link(): void
    {
        $context = $this->releasedContext();
        $otherContext = $this->releasedContext();
        $employeeUser = $this->actAsEmployee($context);
        $this->membership($otherContext['company'], $employeeUser, ['employee.payroll.view'], $otherContext['employee']);

        $this->getJson('/api/v1/employee/payroll', $this->headers($otherContext['company']))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $otherContext['entry']->id);
        $this->getJson('/api/v1/employee/payroll/'.$context['entry']->id, $this->headers($context['company']))->assertOk();
        $this->getJson('/api/v1/employee/payroll/'.$context['entry']->id, $this->headers($otherContext['company']))->assertNotFound();
        $this->getJson('/api/v1/employee/payroll/'.$otherContext['entry']->id, $this->headers($context['company']))->assertNotFound();
    }

    public function test_terminated_employee_retains_released_history_and_reversal_is_disclosed(): void
    {
        $context = $this->releasedContext();
        $context['employee']->update(['status' => 'terminated']);
        $employeeUser = $this->actAsEmployee($context);

        $this->getJson('/api/v1/employee/payroll/'.$context['entry']->id, $this->headers($context['company']))->assertOk();
        Sanctum::actingAs($context['user']);
        $this->postJson("/api/v1/payroll/batches/{$context['batch']->id}/reverse", ['posting_date' => '2026-09-30', 'reason' => 'Correction required'], $this->headers($context['company'], 'reverse-self'))->assertOk();
        Sanctum::actingAs($employeeUser);

        $this->getJson('/api/v1/employee/payroll/'.$context['entry']->id, $this->headers($context['company']))
            ->assertOk()->assertJsonPath('payroll.batch_status', 'CANCELLED');
    }

    public function test_payment_status_comes_only_from_employee_allocations_and_reads_have_no_financial_effects(): void
    {
        $context = $this->releasedContext();
        $entry = $context['entry'];
        $this->postJson("/api/v1/payroll/entries/{$entry->id}/payments", ['financial_account_id' => $context['bank']->id, 'payment_date' => '2026-09-30', 'amount' => 250_000], $this->headers($context['company'], 'self-partial'))->assertCreated();
        $this->actAsEmployee($context);
        $journalCount = Journal::query()->count();
        $journalLineCount = JournalLine::query()->count();
        $auditCount = AuditLog::query()->count();

        $this->getJson('/api/v1/employee/payroll/'.$entry->id, $this->headers($context['company']))
            ->assertOk()->assertJsonPath('paid_amount', 250_000)
            ->assertJsonPath('outstanding_amount', 660_000)
            ->assertJsonPath('payment_status', 'PARTIALLY_PAID');
        $this->getJson('/api/v1/employee/payroll', $this->headers($context['company']))->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($journalCount, Journal::query()->count());
        $this->assertSame($journalLineCount, JournalLine::query()->count());
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertDatabaseCount('payroll_payments', 1);
        $this->assertSame(0, BankTransaction::query()->count());
    }

    public function test_history_is_paginated_and_page_size_validation_is_bounded(): void
    {
        $context = $this->releasedContext();
        $laterPeriod = PayrollPeriod::factory()->for($context['company'])->create(['name' => 'October 2026', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'pay_date' => '2026-10-31', 'created_by' => $context['user']->id]);
        $laterBatch = PayrollBatch::factory()->for($context['company'])->for($laterPeriod, 'period')->create(['status' => 'POSTED', 'posted_at' => now(), 'journal_id' => $context['batch']->journal_id, 'created_by' => $context['user']->id]);
        $laterEntry = PayrollEntry::factory()->for($context['company'])->for($laterBatch, 'batch')->for($context['employee'])->for($context['profile'], 'profile')->create(['released_at' => now()->addMinute(), 'released_by' => $context['user']->id]);
        $this->actAsEmployee($context);

        $this->getJson('/api/v1/employee/payroll?per_page=1', $this->headers($context['company']))
            ->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $laterEntry->id);
        $this->getJson('/api/v1/employee/payroll?per_page=1&page=2', $this->headers($context['company']))
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $context['entry']->id);
        $this->getJson('/api/v1/employee/payroll?per_page=500', $this->headers($context['company']))
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_released_payslip_tracks_full_payment_without_changing_disclosure(): void
    {
        $context = $this->releasedContext();
        $entry = $context['entry'];
        $this->postJson("/api/v1/payroll/entries/{$entry->id}/payments", ['financial_account_id' => $context['bank']->id, 'payment_date' => '2026-09-30', 'amount' => 910_000], $this->headers($context['company'], 'self-full'))->assertCreated();
        $this->actAsEmployee($context);

        $this->getJson('/api/v1/employee/payroll/'.$entry->id, $this->headers($context['company']))
            ->assertOk()->assertJsonPath('payment_status', 'PAID')
            ->assertJsonPath('paid_amount', 910_000)
            ->assertJsonPath('outstanding_amount', 0);
        $this->getJson('/api/v1/employee/payroll', $this->headers($context['company']))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.payment_status', 'PAID');
    }

    public function test_platform_admin_without_link_or_permission_has_no_fabricated_payroll_access(): void
    {
        $context = $this->releasedContext();
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->membership($context['company'], $admin, []);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/employee/payroll', $this->headers($context['company']))->assertForbidden();
        $this->getJson('/api/v1/employee/payroll/'.$context['entry']->id, $this->headers($context['company']))->assertForbidden();
    }

    public function test_disabled_payroll_entitlement_denies_self_history(): void
    {
        $context = $this->releasedContext();
        PlatformModule::query()->firstOrCreate(['key' => 'payroll'], ['name' => 'Payroll']);
        CompanyEntitlement::factory()->for($context['company'])->create(['module_key' => 'payroll', 'is_enabled' => false, 'updated_by' => $context['user']->id]);
        app(EntitlementService::class)->forget($context['company']->id);
        $this->actAsEmployee($context);

        $this->getJson('/api/v1/employee/payroll', $this->headers($context['company']))
            ->assertForbidden()->assertJsonPath('error_code', 'MODULE_NOT_ENTITLED');
    }

    /** @return array<string, mixed> */
    private function releasedContext(): array
    {
        $context = $this->stage8PayrollContext();
        $batchId = $this->createBatch($context);
        foreach (['calculate', 'review', 'approve'] as $action) {
            $this->postJson("/api/v1/payroll/batches/{$batchId}/{$action}", [], $this->headers($context['company']))->assertOk();
        }
        $this->postJson("/api/v1/payroll/batches/{$batchId}/post", [], $this->headers($context['company'], 'post-'.$batchId))->assertOk();
        $context['batch'] = PayrollBatch::query()->findOrFail($batchId);
        $context['entry'] = PayrollEntry::query()->where('payroll_batch_id', $batchId)->firstOrFail();
        $membership = CompanyUser::query()->where('company_id', $context['company']->id)->where('user_id', $context['user']->id)->firstOrFail();
        $membership->role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'payroll.release']));
        $this->postJson("/api/v1/payroll/entries/{$context['entry']->id}/release", [], $this->headers($context['company']))->assertOk();

        return $context;
    }

    /** @param array<string, mixed> $context */
    private function createBatch(array $context): string
    {
        return $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $context['payrollPeriod']->id], $this->headers($context['company']))->assertCreated()->json('id');
    }

    /** @param array<string, mixed> $context */
    private function actAsEmployee(array $context): User
    {
        $employeeUser = User::factory()->create();
        $this->membership($context['company'], $employeeUser, ['employee.payroll.view'], $context['employee']);
        Sanctum::actingAs($employeeUser);

        return $employeeUser;
    }

    /** @param array<int, string> $permissionNames */
    private function membership(Company $company, User $user, array $permissionNames, ?Employee $employee = null): CompanyUser
    {
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Employee role '.$user->id]);
        foreach ($permissionNames as $permissionName) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permissionName]));
        }
        $membership = CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role_id' => $role->id, 'is_active' => true]);
        if ($employee !== null) {
            $membership->forceFill(['employee_id' => $employee->id])->save();
        }

        return $membership;
    }

    /** @return array<string, string> */
    private function headers(Company $company, ?string $key = null): array
    {
        return array_filter(['X-Company-Id' => $company->id, 'Accept' => 'application/json', 'Idempotency-Key' => $key]);
    }
}
