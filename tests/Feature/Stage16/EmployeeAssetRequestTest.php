<?php

namespace Tests\Feature\Stage16;

use App\Http\Controllers\Api\V1\EmployeeAssetRequestController;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeAssetRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeAssetRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_creation_path_used_by_mariadb_concurrency_harness(): void
    {
        [$employeeUser, , $company] = $this->context();
        $request = Request::create('/api/v1/employee/asset-requests', 'POST', [
            'type' => 'NEW_EQUIPMENT', 'item_description' => 'Work laptop', 'reason' => 'Needed for duties',
        ]);
        $request->headers->set('Idempotency-Key', 'direct-asset-request-key');
        $request->attributes->set('company_id', $company->id);
        $request->setUserResolver(fn (): User => $employeeUser);

        $this->assertSame(201, app(EmployeeAssetRequestController::class)->store($request)->getStatusCode());
        $this->assertSame(200, app(EmployeeAssetRequestController::class)->store($request)->getStatusCode());
        $this->assertDatabaseCount('employee_asset_requests', 1);
    }

    public function test_new_equipment_request_approval_is_idempotent_and_has_no_inventory_or_accounting_effect(): void
    {
        [$employeeUser, $admin, $company, $employee] = $this->context();
        $payload = ['type' => 'NEW_EQUIPMENT', 'item_description' => 'Laptop charger', 'reason' => 'Replacement needed'];
        $created = $this->postJson('/api/v1/employee/asset-requests', $payload, $this->headers($company, 'asset-request-1'))
            ->assertCreated()->assertJsonPath('status', 'PENDING')->assertJsonPath('employee_id', $employee->id);
        $id = $created->json('id');
        $this->postJson('/api/v1/employee/asset-requests', $payload, $this->headers($company, 'asset-request-1'))
            ->assertOk()->assertJsonPath('id', $id);
        $this->postJson('/api/v1/employee/asset-requests', [...$payload, 'reason' => 'Changed'], $this->headers($company, 'asset-request-1'))
            ->assertConflict()->assertJsonPath('error_code', 'ASSET_REQUEST_IDEMPOTENCY_CONFLICT');
        $this->postJson('/api/v1/employee/asset-requests', [...$payload, 'type' => 'RETURN'], $this->headers($company, 'asset-request-2'))
            ->assertUnprocessable();
        $this->getJson('/api/v1/employee/asset-requests', $this->headers($company))->assertOk()->assertJsonPath('meta.total', 1);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/hrm/asset-requests/{$id}/approve", ['version' => 1], $this->headers($company))
            ->assertOk()->assertJsonPath('status', 'APPROVED')->assertJsonPath('version', 2);
        $this->postJson("/api/v1/hrm/asset-requests/{$id}/approve", ['version' => 1], $this->headers($company))->assertOk();
        $this->postJson("/api/v1/hrm/asset-requests/{$id}/reject", ['version' => 2, 'reason' => 'No'], $this->headers($company))->assertConflict();
        $this->assertDatabaseCount('employee_asset_requests', 1);
        $this->assertDatabaseCount('employee_asset_request_events', 2);
        $this->assertDatabaseCount('platform_notifications', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('journal_lines', 0);
        $this->assertDatabaseCount('customer_payments', 0);
        Sanctum::actingAs($employeeUser);
        $this->getJson("/api/v1/employee/asset-requests/{$id}", $this->headers($company))->assertOk()
            ->assertJsonPath('events.1.type', 'APPROVED');
    }

    public function test_rejection_requires_reason_and_employee_cannot_approve_own_request(): void
    {
        [$employeeUser, $admin, $company] = $this->context();
        $id = $this->postJson('/api/v1/employee/asset-requests', [
            'type' => 'NEW_EQUIPMENT', 'item_description' => 'Headset', 'reason' => 'Needed for calls',
        ], $this->headers($company, 'asset-request-3'))->assertCreated()->json('id');
        $employeeRole = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $employeeUser->id)->firstOrFail()->role;
        $employeeRole->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'assets.decide']));
        $this->postJson("/api/v1/hrm/asset-requests/{$id}/approve", ['version' => 1], $this->headers($company))
            ->assertForbidden()->assertJsonPath('error_code', 'ASSET_REQUEST_SELF_DECISION_FORBIDDEN');
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/hrm/asset-requests/{$id}/reject", ['version' => 1], $this->headers($company))->assertUnprocessable();
        $this->postJson("/api/v1/hrm/asset-requests/{$id}/reject", ['version' => 2, 'reason' => 'Unavailable'], $this->headers($company))->assertConflict();
        $this->postJson("/api/v1/hrm/asset-requests/{$id}/reject", ['version' => 1, 'reason' => 'Unavailable'], $this->headers($company))
            ->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->assertDatabaseCount('employee_asset_request_events', 2);
        Sanctum::actingAs($employeeUser);
        $this->getJson("/api/v1/employee/asset-requests/{$id}", $this->headers($company))->assertOk()
            ->assertJsonPath('decision_reason', 'Unavailable');
    }

    public function test_former_employee_and_tenant_boundaries_are_enforced(): void
    {
        [$employeeUser, $admin, $company, $employee] = $this->context();
        $otherCompany = Company::factory()->create();
        $otherEmployee = Employee::factory()->for($otherCompany)->create();
        $foreign = EmployeeAssetRequest::factory()->create([
            'company_id' => $otherCompany->id, 'employee_id' => $otherEmployee->id,
        ]);
        $this->getJson("/api/v1/employee/asset-requests/{$foreign->id}", $this->headers($company))->assertNotFound();
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/hrm/asset-requests/{$foreign->id}", $this->headers($company))->assertNotFound();
        $this->postJson("/api/v1/hrm/asset-requests/{$foreign->id}/approve", ['version' => 1], $this->headers($company))->assertNotFound();
        $employee->update(['status' => 'terminated']);
        Sanctum::actingAs($employeeUser);
        $this->getJson('/api/v1/employee/asset-requests', $this->headers($company))->assertOk();
        $this->postJson('/api/v1/employee/asset-requests', [
            'type' => 'NEW_EQUIPMENT', 'item_description' => 'Laptop', 'reason' => 'Former employee',
        ], $this->headers($company, 'asset-request-4'))->assertForbidden();
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    /** @return array{User, User, Company, Employee} */
    private function context(): array
    {
        [$employeeUser, $company] = $this->actingAsCompanyUser(['employee.assets.view', 'employee.assets.request']);
        $employee = Employee::factory()->for($company)->create(['created_by' => $employeeUser->id]);
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $employeeUser->id)->firstOrFail()
            ->forceFill(['employee_id' => $employee->id])->save();
        $admin = User::factory()->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Asset request administrator']);
        foreach (['assets.view', 'assets.decide'] as $permissionName) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permissionName]));
        }
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'is_active' => true]);

        return [$employeeUser, $admin, $company, $employee];
    }

    /** @return array<string, string> */
    private function headers(Company $company, ?string $key = null): array
    {
        return array_filter(['X-Company-Id' => $company->id, 'Accept' => 'application/json', 'Idempotency-Key' => $key]);
    }
}
