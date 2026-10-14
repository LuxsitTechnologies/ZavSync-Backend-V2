<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\CompanyAdminRoleService;
use App\Services\Platform\PlatformAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyAdminRoleModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_system_role_migrates_in_place_and_custom_permissions_remain_untouched(): void
    {
        $company = Company::factory()->create();
        $member = User::factory()->create();
        $legacy = Role::factory()->for($company)->create(['name' => 'Finance Administrator', 'is_system' => true]);
        $custom = Role::factory()->for($company)->create(['name' => 'Finance Manager', 'is_system' => false]);
        $customPermission = Permission::query()->firstOrCreate(['name' => 'crm.view']);
        $custom->permissions()->sync([$customPermission->id]);
        foreach (['billing.view', 'billing.manage', 'portal.settings.view', 'portal.settings.manage', 'platform.companies.approve', 'fbr.hs.manage'] as $name) {
            Permission::query()->firstOrCreate(['name' => $name]);
        }
        $membership = CompanyUser::factory()->for($company)->for($member)->create(['role_id' => $legacy->id]);
        $membership->roles()->attach($legacy->id);

        (require database_path('migrations/2026_10_14_000002_migrate_company_admin_system_roles.php'))->up();
        $service = app(CompanyAdminRoleService::class);
        $admin = Role::query()->findOrFail($legacy->id);
        $service->provision($company->id);

        $this->assertSame($legacy->id, $admin->id);
        $this->assertSame('Admin', $admin->fresh()->name);
        $this->assertSame($admin->id, $membership->fresh()->role_id);
        $this->assertDatabaseHas('company_user_role', ['company_user_id' => $membership->id, 'role_id' => $admin->id]);
        $this->assertSame(1, Role::query()->where('company_id', $company->id)->where('name', 'Admin')->count());
        $this->assertEqualsCanonicalizing(['crm.view'], $custom->fresh()->permissions->pluck('name')->all());
        foreach (['billing.view', 'billing.manage', 'portal.settings.view', 'portal.settings.manage'] as $name) {
            $this->assertTrue($admin->fresh()->permissions->contains('name', $name));
        }
        $this->assertFalse($admin->fresh()->permissions->contains('name', 'platform.companies.approve'));
        $this->assertFalse($admin->fresh()->permissions->contains('name', 'fbr.hs.manage'));
        $this->assertFalse($admin->fresh()->permissions->contains('name', 'migration.manage'));
    }

    public function test_auth_payload_separates_platform_owner_role_and_actual_employee_link(): void
    {
        $user = User::factory()->create(['is_platform_admin' => false]);
        $companyA = Company::factory()->create(['created_by' => $user->id]);
        $companyB = Company::factory()->create();
        foreach (['billing.view', 'employee.self.view'] as $name) {
            Permission::query()->firstOrCreate(['name' => $name]);
        }
        $admin = app(CompanyAdminRoleService::class)->provision($companyA->id);
        $membership = CompanyUser::factory()->for($companyA)->for($user)->create(['role_id' => $admin->id]);
        $membership->roles()->attach($admin->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('user.is_platform_admin', false)
            ->assertJsonPath('companies.0.roles.0', 'Admin')
            ->assertJsonPath('companies.0.is_company_owner', true)
            ->assertJsonPath('companies.0.employee_linked', false)
            ->assertJsonPath('companies.0.employee_portal_available', false);
        $this->assertFalse($user->hasCompanyPermission($companyB->id, 'billing.view'));
        $this->getJson('/api/v1/platform/admin/companies', ['X-Company-Id' => $companyA->id])->assertForbidden();
        $this->getJson('/api/v1/platform/roles', ['X-Company-Id' => $companyA->id])->assertOk()
            ->assertJsonPath('roles.0.name', 'Admin')->assertJsonPath('roles.0.memberships_count', 1);
        $this->getJson('/api/v1/platform/roles', ['X-Company-Id' => $companyB->id])->assertForbidden();
        $employee = Employee::factory()->for($companyA)->create();
        $membership->forceFill(['employee_id' => $employee->id])->save();
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('companies.0.employee_linked', true)
            ->assertJsonPath('companies.0.employee_portal_available', true);
    }

    public function test_duplicate_legacy_system_roles_merge_without_losing_invitation_assignments(): void
    {
        $company = Company::factory()->create();
        $admin = Role::factory()->for($company)->create(['name' => 'Admin', 'is_system' => true]);
        $legacy = Role::factory()->for($company)->create(['name' => 'Company Administrator', 'is_system' => true]);
        $invitation = CompanyInvitation::factory()->for($company)->create(['role_ids' => [$legacy->id]]);
        $membership = CompanyUser::factory()->for($company)->create(['role_id' => $legacy->id]);
        $membership->roles()->attach($legacy->id);

        app(CompanyAdminRoleService::class)->provision($company->id);

        $this->assertSame($admin->id, $membership->fresh()->role_id);
        $this->assertSame([$admin->id], $invitation->fresh()->role_ids);
        $this->assertDatabaseHas('company_user_role', ['company_user_id' => $membership->id, 'role_id' => $admin->id]);
        $this->assertDatabaseMissing('roles', ['id' => $legacy->id]);
    }

    public function test_platform_authority_is_explicit_and_does_not_live_on_company_admin_role(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);
        $company = Company::factory()->create();
        $role = app(CompanyAdminRoleService::class)->provision($company->id);
        CompanyUser::factory()->for($company)->for($user)->create(['role_id' => $role->id]);
        $this->assertFalse($user->hasCompanyPermission($company->id, 'platform.companies.view'));
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/platform/admin/companies', ['X-Company-Id' => $company->id])->assertForbidden();
        app(PlatformAuthority::class)->grantAll($user);
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('user.is_platform_admin', true);
        $payload = $this->getJson('/api/v1/auth/me')->json();
        $this->assertContains('platform.companies.view', $payload['user']['platform_permissions']);
        $this->assertNotContains('platform.companies.view', $payload['companies'][0]['permissions']);
        $this->getJson('/api/v1/platform/admin/companies', ['X-Company-Id' => $company->id])->assertOk();
        $globalPermission = Permission::query()->where('name', 'platform.companies.view')->sole();
        $this->postJson('/api/v1/platform/roles', [
            'name' => 'Unsafe global assignment', 'permission_ids' => [$globalPermission->id],
        ], ['X-Company-Id' => $company->id])->assertForbidden()->assertJsonPath('error_code', 'PRIVILEGE_ESCALATION_DENIED');
    }

    public function test_existing_platform_grants_survive_company_admin_role_migration(): void
    {
        $user = User::factory()->create(['is_platform_admin' => true]);
        $company = Company::factory()->create();
        $role = Role::factory()->for($company)->create(['name' => 'Finance Administrator', 'is_system' => true]);
        $permission = Permission::query()->firstOrCreate(['name' => 'fbr.hs.manage']);
        $role->permissions()->attach($permission);
        CompanyUser::factory()->for($company)->for($user)->create(['role_id' => $role->id]);

        (require database_path('migrations/2026_10_14_000001_migrate_platform_authority_grants.php'))->up();
        (require database_path('migrations/2026_10_14_000002_migrate_company_admin_system_roles.php'))->up();

        $this->assertFalse($user->hasCompanyPermission($company->id, 'fbr.hs.manage'));
        $this->assertTrue(app(PlatformAuthority::class)->allows($user, 'fbr.hs.manage'));
        $this->assertSame('Admin', $role->fresh()->name);
    }
}
