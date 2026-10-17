<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\PlatformAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformGlobalContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_only_administrator_can_list_and_view_companies_without_company_context(): void
    {
        $administrator = $this->platformAdministrator(['platform.companies.view']);
        $company = Company::factory()->create();

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('user.is_platform_admin', true)
            ->assertJsonPath('user.platform_permissions.0', 'platform.companies.view')
            ->assertJsonCount(0, 'companies');
        $this->getJson('/api/v1/platform/admin/companies')->assertOk()
            ->assertJsonPath('data.0.id', $company->id);
        $this->getJson('/api/v1/platform/admin/companies/'.$company->id)->assertOk()
            ->assertJsonPath('id', $company->id);
        $this->assertFalse($administrator->belongsToCompany($company->id));
    }

    public function test_platform_only_administrator_can_use_global_approval_activity_and_operations_routes(): void
    {
        $this->platformAdministrator([
            'platform.companies.approve', 'platform.companies.activate', 'platform.jobs.view',
        ]);
        $company = Company::factory()->create(['approval_status' => 'APPROVED', 'is_active' => true, 'profile_version' => 1]);

        $this->postJson('/api/v1/platform/admin/companies/'.$company->id.'/approval', [
            'approval_status' => 'REJECTED', 'profile_version' => $company->profile_version,
        ])->assertOk()->assertJsonPath('approval_status', 'REJECTED');
        $this->patchJson('/api/v1/platform/admin/companies/'.$company->id.'/activity', [
            'is_active' => false, 'profile_version' => $company->fresh()->profile_version,
        ])->assertOk()->assertJsonPath('is_active', false);
        $this->getJson('/api/v1/platform/system/failed-jobs')->assertOk();
        $this->getJson('/api/v1/platform/system/schedule')->assertOk();
    }

    public function test_company_administrator_and_company_role_cannot_grant_global_platform_access(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['platform.companies.view']);
        $this->getJson('/api/v1/platform/admin/companies', ['X-Company-Id' => $company->id])->assertForbidden();

        $role = Role::query()->where('company_id', $company->id)->firstOrFail();
        $role->permissions()->syncWithoutDetaching(Permission::query()->firstOrCreate(['name' => 'platform.jobs.view'])->id);
        $this->getJson('/api/v1/platform/system/schedule', ['X-Company-Id' => $company->id])->assertForbidden();
        $this->assertFalse($user->fresh()->is_platform_admin);
    }

    public function test_platform_administrator_requires_the_specific_global_permission(): void
    {
        $this->platformAdministrator(['platform.companies.view']);

        $this->getJson('/api/v1/platform/system/schedule')->assertForbidden()
            ->assertJsonPath('error_code', 'PERMISSION_DENIED');
        $this->postJson('/api/v1/platform/admin/companies/not-a-company/approval', [
            'approval_status' => 'APPROVED', 'profile_version' => 1,
        ])->assertForbidden()->assertJsonPath('error_code', 'PERMISSION_DENIED');
    }

    public function test_unauthenticated_global_platform_routes_return_safe_json_401(): void
    {
        $this->getJson('/api/v1/platform/admin/companies')->assertUnauthorized()->assertDontSee('Route [login]');
        $this->getJson('/api/v1/platform/system/schedule')->assertUnauthorized()->assertDontSee('Route [login]');
    }

    public function test_company_scoped_routes_still_require_approved_active_authorized_membership(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['platform.users.view']);
        $foreignCompany = Company::factory()->create();

        $this->getJson('/api/v1/platform/users')->assertUnprocessable()
            ->assertJsonPath('error_code', 'COMPANY_CONTEXT_REQUIRED');
        $this->getJson('/api/v1/platform/users', ['X-Company-Id' => $foreignCompany->id])->assertForbidden()
            ->assertJsonPath('error_code', 'TENANT_ACCESS_DENIED');
        $company->update(['is_active' => false]);
        $this->getJson('/api/v1/platform/users', ['X-Company-Id' => $company->id])->assertForbidden()
            ->assertJsonPath('error_code', 'COMPANY_INACTIVE');
        $company->update(['is_active' => true]);
        $this->getJson('/api/v1/platform/users', ['X-Company-Id' => $company->id])->assertOk();
        $company->update(['approval_status' => 'PENDING']);
        $this->getJson('/api/v1/platform/users', ['X-Company-Id' => $company->id])->assertForbidden()
            ->assertJsonPath('error_code', 'COMPANY_NOT_APPROVED');
        $company->update(['approval_status' => 'APPROVED']);
        $role = Role::query()->where('company_id', $company->id)->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('name', 'platform.users.view')->firstOrFail()->id);
        $this->getJson('/api/v1/platform/users', ['X-Company-Id' => $company->id])->assertForbidden()
            ->assertJsonPath('error_code', 'PERMISSION_DENIED');
        $this->assertFalse($user->belongsToCompany($foreignCompany->id));
    }

    public function test_target_company_profile_edit_keeps_its_company_context_and_target_permission(): void
    {
        $this->platformAdministrator(['platform.companies.view']);
        $target = Company::factory()->create();

        $this->patchJson('/api/v1/platform/admin/companies/'.$target->id, [
            'profile_version' => $target->profile_version, 'name' => 'Changed Name',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'COMPANY_CONTEXT_REQUIRED');
        $this->assertSame($target->name, $target->fresh()->name);
    }

    /** @param array<int, string> $permissions */
    private function platformAdministrator(array $permissions): User
    {
        $administrator = User::factory()->create(['is_platform_admin' => true]);
        app(PlatformAuthority::class)->grant($administrator, collect($permissions)
            ->map(fn (string $name): int => Permission::query()->firstOrCreate(['name' => $name])->id)->all());
        Sanctum::actingAs($administrator);

        return $administrator;
    }
}
