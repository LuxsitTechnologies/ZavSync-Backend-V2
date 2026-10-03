<?php

namespace Tests\Feature\Stage16;

use App\Http\Controllers\Api\V1\EmployeeExpenseClaimController;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeExpenseCategory;
use App\Models\EmployeeExpenseClaim;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeExpenseClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_creation_path_used_by_mariadb_concurrency_harness(): void
    {
        [$employeeUser, $admin, $company] = $this->context();
        $categoryId = $this->category($admin, $company);
        $request = Request::create('/api/v1/employee/expense-claims', 'POST', [
            'category_id' => $categoryId, 'title' => 'Travel reimbursement',
            'amount_minor' => 12500, 'expense_date' => now()->subDay()->toDateString(),
        ]);
        $request->headers->set('Idempotency-Key', 'direct-expense-claim-key');
        $request->attributes->set('company_id', $company->id);
        $request->setUserResolver(fn (): User => $employeeUser);

        $this->assertSame(201, app(EmployeeExpenseClaimController::class)->store($request)->getStatusCode());
        $this->assertSame(200, app(EmployeeExpenseClaimController::class)->store($request)->getStatusCode());
        $this->assertDatabaseCount('employee_expense_claims', 1);
    }

    public function test_claim_receipt_submission_approval_and_financial_firewall(): void
    {
        [$employeeUser, $admin, $company, $employee] = $this->context();
        Storage::fake('local');
        $categoryId = $this->category($admin, $company);
        Sanctum::actingAs($employeeUser);
        $this->getJson('/api/v1/employee/expense-categories', $this->headers($company))->assertOk()->assertJsonPath('data.0.id', $categoryId);
        $payload = $this->claimPayload($categoryId);
        $created = $this->postJson('/api/v1/employee/expense-claims', $payload, $this->headers($company, 'expense-claim-1'))
            ->assertCreated()->assertJsonPath('status', 'DRAFT')->assertJsonPath('amount_minor', 12345)
            ->assertJsonPath('currency', 'PKR')->assertJsonPath('employee_id', $employee->id);
        $id = $created->json('id');
        $this->postJson('/api/v1/employee/expense-claims', $payload, $this->headers($company, 'expense-claim-1'))
            ->assertOk()->assertJsonPath('id', $id);
        $this->postJson('/api/v1/employee/expense-claims', [...$payload, 'amount_minor' => 12346], $this->headers($company, 'expense-claim-1'))
            ->assertConflict()->assertJsonPath('error_code', 'EXPENSE_CLAIM_IDEMPOTENCY_CONFLICT');
        $this->postJson('/api/v1/employee/expense-claims', [...$payload, 'amount_minor' => 12.5], $this->headers($company, 'expense-claim-2'))
            ->assertUnprocessable();
        $this->json('POST', '/api/v1/employee/expense-claims', [...$payload, 'amount_minor' => 12.0],
            $this->headers($company, 'expense-claim-float'), JSON_PRESERVE_ZERO_FRACTION)
            ->assertUnprocessable();
        $this->postJson('/api/v1/employee/expense-claims', [...$payload, 'amount_minor' => 0], $this->headers($company, 'expense-claim-3'))
            ->assertUnprocessable();
        $receipt = $this->postJson("/api/v1/employee/expense-claims/{$id}/receipt", [
            'file' => UploadedFile::fake()->createWithContent('receipt.pdf', '%PDF-1.4 private receipt'), 'version' => 1,
        ], $this->headers($company, 'expense-receipt-1'))->assertOk()->assertJsonPath('version', 2);
        $this->assertSame('receipt.pdf', $receipt->json('receipt.original_filename'));
        $this->postJson("/api/v1/employee/expense-claims/{$id}/receipt", [
            'file' => UploadedFile::fake()->createWithContent('receipt.pdf', '%PDF-1.4 private receipt'), 'version' => 1,
        ], $this->headers($company, 'expense-receipt-1'))->assertOk()->assertJsonPath('version', 2);
        $this->get("/api/v1/employee/expense-claims/{$id}/receipt", $this->headers($company))->assertOk();
        $this->getJson('/api/v1/platform/documents', $this->headers($company))->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/employee/expense-claims/{$id}/submit", ['version' => 1], $this->headers($company))->assertConflict();
        $this->postJson("/api/v1/employee/expense-claims/{$id}/submit", ['version' => 2], $this->headers($company))
            ->assertOk()->assertJsonPath('status', 'SUBMITTED')->assertJsonPath('version', 3);
        $this->postJson("/api/v1/employee/expense-claims/{$id}/submit", ['version' => 2], $this->headers($company))->assertOk();
        $this->patchJson("/api/v1/employee/expense-claims/{$id}", [...$payload, 'version' => 3], $this->headers($company))->assertConflict();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/hrm/expense-claims/{$id}/approve", ['version' => 3], $this->headers($company))
            ->assertOk()->assertJsonPath('status', 'APPROVED')->assertJsonPath('version', 4);
        $this->postJson("/api/v1/hrm/expense-claims/{$id}/approve", ['version' => 3], $this->headers($company))->assertOk();
        $this->assertDatabaseCount('employee_expense_claims', 1);
        $this->assertDatabaseCount('employee_expense_claim_events', 4);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('journal_lines', 0);
        $this->assertDatabaseCount('customer_payments', 0);
        $this->assertDatabaseCount('bank_transactions', 0);
        Sanctum::actingAs($employeeUser);
        $this->getJson("/api/v1/employee/expense-claims/{$id}", $this->headers($company))->assertOk()
            ->assertJsonPath('events.3.type', 'APPROVED');
    }

    public function test_draft_edits_rejection_self_approval_and_category_deactivation(): void
    {
        [$employeeUser, $admin, $company] = $this->context();
        $categoryId = $this->category($admin, $company);
        Sanctum::actingAs($employeeUser);
        $payload = $this->claimPayload($categoryId);
        $id = $this->postJson('/api/v1/employee/expense-claims', $payload, $this->headers($company, 'expense-claim-4'))->assertCreated()->json('id');
        $this->patchJson("/api/v1/employee/expense-claims/{$id}", [...$payload, 'amount_minor' => 20000, 'version' => 1], $this->headers($company))
            ->assertOk()->assertJsonPath('version', 2)->assertJsonPath('amount_minor', 20000);
        $this->patchJson("/api/v1/employee/expense-claims/{$id}", [...$payload, 'amount_minor' => 20000, 'version' => 1], $this->headers($company))->assertOk()->assertJsonPath('version', 2);
        $this->patchJson("/api/v1/employee/expense-claims/{$id}", [...$payload, 'amount_minor' => 30000, 'version' => 1], $this->headers($company))->assertConflict();
        $this->postJson("/api/v1/employee/expense-claims/{$id}/submit", ['version' => 2], $this->headers($company))->assertOk();
        $role = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $employeeUser->id)->firstOrFail()->role;
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'expenses.approve']));
        $this->postJson("/api/v1/hrm/expense-claims/{$id}/approve", ['version' => 3], $this->headers($company))
            ->assertForbidden()->assertJsonPath('error_code', 'EXPENSE_CLAIM_SELF_APPROVAL_FORBIDDEN');
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/hrm/expense-claims/{$id}/reject", ['version' => 3], $this->headers($company))->assertUnprocessable();
        $this->postJson("/api/v1/hrm/expense-claims/{$id}/reject", ['version' => 3, 'reason' => 'Receipt unclear'], $this->headers($company))
            ->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->patchJson("/api/v1/hrm/expense-categories/{$categoryId}/active", ['is_active' => false, 'version' => 1], $this->headers($company))
            ->assertOk()->assertJsonPath('is_active', false);
        Sanctum::actingAs($employeeUser);
        $this->postJson('/api/v1/employee/expense-claims', $payload, $this->headers($company, 'expense-claim-5'))->assertNotFound();
        $this->getJson("/api/v1/employee/expense-claims/{$id}", $this->headers($company))->assertOk()->assertJsonPath('decision_reason', 'Receipt unclear');
    }

    public function test_cross_company_receipt_and_former_employee_boundaries(): void
    {
        [$employeeUser, $admin, $company, $employee] = $this->context();
        $categoryId = $this->category($admin, $company);
        Sanctum::actingAs($employeeUser);
        $id = $this->postJson('/api/v1/employee/expense-claims', $this->claimPayload($categoryId), $this->headers($company, 'expense-claim-6'))
            ->assertCreated()->json('id');
        $otherCompany = Company::factory()->create();
        $otherEmployee = Employee::factory()->for($otherCompany)->create();
        $foreignCategory = EmployeeExpenseCategory::factory()->create(['company_id' => $otherCompany->id]);
        $foreignClaim = EmployeeExpenseClaim::factory()->create(['company_id' => $otherCompany->id,
            'employee_id' => $otherEmployee->id, 'category_id' => $foreignCategory->id]);
        $this->getJson("/api/v1/employee/expense-claims/{$foreignClaim->id}", $this->headers($company))->assertNotFound();
        $this->get("/api/v1/employee/expense-claims/{$foreignClaim->id}/receipt", $this->headers($company))->assertNotFound();
        $this->postJson('/api/v1/employee/expense-claims', $this->claimPayload($foreignCategory->id), $this->headers($company, 'expense-claim-7'))->assertNotFound();
        $employee->update(['status' => 'terminated']);
        $this->getJson("/api/v1/employee/expense-claims/{$id}", $this->headers($company))->assertOk();
        $this->postJson('/api/v1/employee/expense-claims', $this->claimPayload($categoryId), $this->headers($company, 'expense-claim-8'))->assertForbidden();
        $this->assertDatabaseCount('journals', 0);
    }

    /** @return array{User, User, Company, Employee} */
    private function context(): array
    {
        [$employeeUser, $company] = $this->actingAsCompanyUser(['employee.expenses.view', 'employee.expenses.create', 'employee.expenses.edit', 'employee.expenses.submit', 'platform.documents.view']);
        $employee = Employee::factory()->for($company)->create(['created_by' => $employeeUser->id]);
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $employeeUser->id)->firstOrFail()
            ->forceFill(['employee_id' => $employee->id])->save();
        $admin = User::factory()->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Expense administrator']);
        foreach (['expenses.view', 'expenses.categories.manage', 'expenses.approve'] as $permissionName) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permissionName]));
        }
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'is_active' => true]);

        return [$employeeUser, $admin, $company, $employee];
    }

    private function category(User $admin, Company $company): string
    {
        Sanctum::actingAs($admin);

        return $this->postJson('/api/v1/hrm/expense-categories', ['name' => 'Travel'], $this->headers($company, 'expense-category-1'))
            ->assertCreated()->json('id');
    }

    /** @return array<string, mixed> */
    private function claimPayload(string $categoryId): array
    {
        return ['category_id' => $categoryId, 'title' => 'Taxi fare', 'description' => 'Client meeting',
            'amount_minor' => 12345, 'expense_date' => now()->subDay()->toDateString()];
    }

    /** @return array<string, string> */
    private function headers(Company $company, ?string $key = null): array
    {
        return array_filter(['X-Company-Id' => $company->id, 'Accept' => 'application/json', 'Idempotency-Key' => $key]);
    }
}
