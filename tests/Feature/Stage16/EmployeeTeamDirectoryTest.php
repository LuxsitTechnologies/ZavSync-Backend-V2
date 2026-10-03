<?php

namespace Tests\Feature\Stage16;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeTeam;
use App\Models\EmployeeTeamMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeTeamDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_membership_lead_manager_and_private_directory_lifecycle(): void
    {
        [$employeeUser, $admin, $company, $employee] = $this->context();
        $colleague = Employee::factory()->for($company)->create(['full_name' => 'Colleague One']);
        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/v1/hrm/teams', ['name' => 'Finance Operations'], $this->headers($company, 'team-create-1'))
            ->assertCreated()->assertJsonPath('version', 1);
        $teamId = $created->json('id');
        $this->postJson('/api/v1/hrm/teams', ['name' => 'Finance Operations'], $this->headers($company, 'team-create-1'))
            ->assertOk()->assertJsonPath('id', $teamId);
        $this->postJson('/api/v1/hrm/teams', ['name' => 'Different'], $this->headers($company, 'team-create-1'))
            ->assertConflict()->assertJsonPath('error_code', 'TEAM_IDEMPOTENCY_CONFLICT');
        $this->postJson("/api/v1/hrm/teams/{$teamId}/members", ['employee_id' => $employee->id, 'version' => 1], $this->headers($company))
            ->assertOk()->assertJsonPath('version', 2);
        $this->postJson("/api/v1/hrm/teams/{$teamId}/members", ['employee_id' => $employee->id, 'version' => 1], $this->headers($company))
            ->assertOk()->assertJsonPath('version', 2);
        $this->postJson("/api/v1/hrm/teams/{$teamId}/members", ['employee_id' => $colleague->id, 'version' => 2], $this->headers($company))
            ->assertOk()->assertJsonPath('version', 3);
        $this->patchJson("/api/v1/hrm/teams/{$teamId}/lead", ['employee_id' => $colleague->id, 'version' => 3], $this->headers($company))
            ->assertOk()->assertJsonPath('lead.id', $colleague->id)->assertJsonPath('version', 4);
        $this->patchJson("/api/v1/hrm/employees/{$employee->id}/manager", ['manager_employee_id' => $colleague->id, 'version' => 1], $this->headers($company))
            ->assertOk()->assertJsonPath('version', 2);
        $this->patchJson("/api/v1/hrm/employees/{$colleague->id}/manager", ['manager_employee_id' => $employee->id, 'version' => 1], $this->headers($company))
            ->assertConflict()->assertJsonPath('error_code', 'MANAGER_CYCLE_FORBIDDEN');
        $this->deleteJson("/api/v1/hrm/teams/{$teamId}/members/{$colleague->id}", ['version' => 4], $this->headers($company))->assertConflict();

        Sanctum::actingAs($employeeUser);
        $directory = $this->getJson('/api/v1/employee/directory', $this->headers($company))->assertOk();
        $this->assertSame(2, $directory->json('meta.total'));
        foreach ($directory->json('data') as $card) {
            $this->assertEqualsCanonicalizing(['id', 'full_name', 'department', 'designation', 'location'], array_keys($card));
        }
        $this->getJson('/api/v1/employee/my-teams', $this->headers($company))->assertOk()
            ->assertJsonPath('data.0.id', $teamId)->assertJsonPath('manager.id', $colleague->id);
        $this->getJson("/api/v1/employee/my-teams/{$teamId}", $this->headers($company))->assertOk()->assertJsonPath('meta.total', 2);
        $colleague->forceFill(['status' => 'resigned'])->save();
        $this->getJson('/api/v1/employee/my-teams', $this->headers($company))->assertOk()
            ->assertJsonPath('data.0.lead', null)->assertJsonPath('data.0.member_count', 1)
            ->assertJsonPath('manager', null);
        $this->getJson("/api/v1/employee/my-teams/{$teamId}", $this->headers($company))
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_cross_company_and_nonmember_team_ids_are_inaccessible_and_former_employee_cannot_view_current_directory(): void
    {
        [$employeeUser, $admin, $company, $employee] = $this->context();
        $foreignCompany = Company::factory()->create();
        $foreignEmployee = Employee::factory()->for($foreignCompany)->create();
        $foreignTeam = EmployeeTeam::factory()->create(['company_id' => $foreignCompany->id]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/hrm/teams/{$foreignTeam->id}/members", ['employee_id' => $employee->id, 'version' => 1], $this->headers($company))->assertNotFound();
        $teamId = $this->postJson('/api/v1/hrm/teams', ['name' => 'People Team'], $this->headers($company, 'team-create-2'))->assertCreated()->json('id');
        $this->postJson("/api/v1/hrm/teams/{$teamId}/members", ['employee_id' => $foreignEmployee->id, 'version' => 1], $this->headers($company))->assertNotFound();
        $this->patchJson("/api/v1/hrm/employees/{$employee->id}/manager", ['manager_employee_id' => $foreignEmployee->id, 'version' => 1], $this->headers($company))->assertNotFound();
        $this->getJson("/api/v1/hrm/teams/{$foreignTeam->id}", $this->headers($company))->assertNotFound();
        Sanctum::actingAs($employeeUser);
        $this->getJson("/api/v1/employee/my-teams/{$teamId}", $this->headers($company))->assertNotFound();
        $this->getJson("/api/v1/employee/my-teams/{$foreignTeam->id}", $this->headers($company))->assertNotFound();
        $employee->update(['status' => 'resigned']);
        $this->getJson('/api/v1/employee/directory', $this->headers($company))->assertForbidden();
        $this->getJson('/api/v1/employee/my-teams', $this->headers($company))->assertForbidden();
        $this->assertDatabaseCount('employee_team_memberships', 0);
        $this->assertNull($employee->fresh()->manager_employee_id);
    }

    public function test_membership_remove_and_reactivation_are_versioned_and_audited(): void
    {
        [, $admin, $company, $employee] = $this->context();
        Sanctum::actingAs($admin);
        $teamId = $this->postJson('/api/v1/hrm/teams', ['name' => 'Operations'], $this->headers($company, 'team-create-3'))->assertCreated()->json('id');
        $this->postJson("/api/v1/hrm/teams/{$teamId}/members", ['employee_id' => $employee->id, 'version' => 1], $this->headers($company))->assertOk();
        $this->deleteJson("/api/v1/hrm/teams/{$teamId}/members/{$employee->id}", ['version' => 1], $this->headers($company))->assertConflict();
        $this->deleteJson("/api/v1/hrm/teams/{$teamId}/members/{$employee->id}", ['version' => 2], $this->headers($company))->assertOk()->assertJsonPath('version', 3);
        $this->deleteJson("/api/v1/hrm/teams/{$teamId}/members/{$employee->id}", ['version' => 2], $this->headers($company))->assertOk()->assertJsonPath('version', 3);
        $this->postJson("/api/v1/hrm/teams/{$teamId}/members", ['employee_id' => $employee->id, 'version' => 3], $this->headers($company))
            ->assertOk()->assertJsonPath('version', 4);
        $this->assertDatabaseCount('employee_team_memberships', 1);
        $this->assertTrue(EmployeeTeamMembership::query()->firstOrFail()->is_active);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'employee_team_member_removed']);
    }

    public function test_former_employees_cannot_be_assigned_to_current_teams_or_reporting_lines(): void
    {
        [, $admin, $company, $employee] = $this->context();
        $former = Employee::factory()->for($company)->create(['status' => 'terminated']);
        Sanctum::actingAs($admin);
        $teamId = $this->postJson('/api/v1/hrm/teams', ['name' => 'Current Team'], $this->headers($company, 'team-create-former'))
            ->assertCreated()->json('id');

        $this->postJson("/api/v1/hrm/teams/{$teamId}/members", ['employee_id' => $former->id, 'version' => 1], $this->headers($company))
            ->assertConflict()->assertJsonPath('error_code', 'EMPLOYEE_INACTIVE');
        $this->postJson("/api/v1/hrm/teams/{$teamId}/members", ['employee_id' => $employee->id, 'version' => 1], $this->headers($company))
            ->assertOk()->assertJsonPath('version', 2);
        $this->patchJson("/api/v1/hrm/teams/{$teamId}/lead", ['employee_id' => $former->id, 'version' => 2], $this->headers($company))
            ->assertConflict()->assertJsonPath('error_code', 'EMPLOYEE_INACTIVE');
        $this->patchJson("/api/v1/hrm/employees/{$employee->id}/manager", ['manager_employee_id' => $former->id, 'version' => 1], $this->headers($company))
            ->assertConflict()->assertJsonPath('error_code', 'EMPLOYEE_INACTIVE');
        $this->patchJson("/api/v1/hrm/employees/{$former->id}/manager", ['manager_employee_id' => $employee->id, 'version' => 1], $this->headers($company))
            ->assertConflict()->assertJsonPath('error_code', 'EMPLOYEE_INACTIVE');

        $this->assertDatabaseCount('employee_team_memberships', 1);
        $this->assertNull($employee->fresh()->manager_employee_id);
    }

    /** @return array{User, User, Company, Employee} */
    private function context(): array
    {
        [$employeeUser, $company] = $this->actingAsCompanyUser(['employee.directory.view', 'employee.teams.view']);
        $employee = Employee::factory()->for($company)->create(['created_by' => $employeeUser->id]);
        CompanyUser::query()->where('company_id', $company->id)->where('user_id', $employeeUser->id)->firstOrFail()
            ->forceFill(['employee_id' => $employee->id])->save();
        $admin = User::factory()->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Team administrator']);
        foreach (['teams.view', 'teams.manage'] as $permissionName) {
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
