<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_user_list_is_searchable_and_tenant_scoped(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.users.view']);
        $member = CompanyUser::factory()->for($company)->for(User::factory()->state(['name' => 'Searchable Person']))->create();
        CompanyUser::factory()->create();

        $this->getJson('/api/v1/platform/users?search=Searchable', ['X-Company-Id' => $company->id])
            ->assertOk()->assertJsonPath('data.0.id', $member->id)->assertJsonCount(1, 'data');
    }

    public function test_company_admin_can_suspend_and_reactivate_membership_without_disabling_identity(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.users.manage']);
        $member = CompanyUser::factory()->for($company)->for(User::factory())->create();

        $this->patchJson("/api/v1/platform/users/{$member->id}/status", ['active' => false], ['X-Company-Id' => $company->id])->assertOk();
        $this->assertDatabaseHas('company_users', ['id' => $member->id, 'is_active' => false]);
        $this->assertDatabaseHas('users', ['id' => $member->user_id]);
        $this->patchJson("/api/v1/platform/users/{$member->id}/status", ['active' => true], ['X-Company-Id' => $company->id])->assertOk();
        $this->assertDatabaseHas('company_users', ['id' => $member->id, 'is_active' => true, 'suspended_at' => null]);
    }

    public function test_company_admin_cannot_administer_another_company_membership(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.users.manage']);
        $foreign = CompanyUser::factory()->create();

        $this->patchJson("/api/v1/platform/users/{$foreign->id}/status", ['active' => false], ['X-Company-Id' => $company->id])->assertNotFound();
        $this->assertTrue($foreign->fresh()->is_active);
    }

    public function test_role_creation_and_assignment_support_multiple_roles(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.roles.manage', 'platform.users.manage', 'crm.view']);
        $permissions = Permission::query()->whereIn('name', ['platform.roles.manage', 'crm.view'])->pluck('id')->all();
        $response = $this->postJson('/api/v1/platform/roles', ['name' => 'Sales Auditor', 'permission_ids' => $permissions], ['X-Company-Id' => $company->id])->assertCreated();
        $roleId = $response->json('id');
        $member = CompanyUser::factory()->for($company)->for(User::factory())->create();

        $this->putJson("/api/v1/platform/users/{$member->id}/roles", ['role_ids' => [$roleId]], ['X-Company-Id' => $company->id])->assertOk();
        $this->assertDatabaseHas('company_user_role', ['company_user_id' => $member->id, 'role_id' => $roleId]);
    }

    public function test_roles_can_be_removed_without_deleting_company_membership(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.users.manage']);
        $member = CompanyUser::factory()->for($company)->for(User::factory())->create();

        $this->putJson("/api/v1/platform/users/{$member->id}/roles", ['role_ids' => []], ['X-Company-Id' => $company->id])->assertOk();

        $this->assertDatabaseHas('company_users', ['id' => $member->id, 'role_id' => null]);
        $this->assertDatabaseMissing('company_user_role', ['company_user_id' => $member->id]);
    }

    public function test_role_manager_cannot_grant_permission_they_do_not_hold(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.roles.manage']);
        $permission = Permission::query()->firstOrCreate(['name' => 'payroll.post']);

        $this->postJson('/api/v1/platform/roles', ['name' => 'Escalated', 'permission_ids' => [$permission->id]], ['X-Company-Id' => $company->id])
            ->assertForbidden()->assertJsonPath('error_code', 'PRIVILEGE_ESCALATION_DENIED');
        $this->assertDatabaseMissing('roles', ['company_id' => $company->id, 'name' => 'Escalated']);
    }

    public function test_role_manager_cannot_assign_a_role_containing_permissions_they_do_not_hold(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.users.manage']);
        $unheldPermission = Permission::query()->firstOrCreate(['name' => 'payroll.post']);
        $role = Role::factory()->for($company)->create();
        $role->permissions()->attach($unheldPermission);
        $member = CompanyUser::factory()->for($company)->for(User::factory())->create();

        $this->putJson("/api/v1/platform/users/{$member->id}/roles", ['role_ids' => [$role->id]], ['X-Company-Id' => $company->id])
            ->assertForbidden()->assertJsonPath('error_code', 'PRIVILEGE_ESCALATION_DENIED');
        $this->assertDatabaseMissing('company_user_role', ['company_user_id' => $member->id, 'role_id' => $role->id]);
    }

    public function test_role_manager_cannot_clone_a_role_containing_permissions_they_do_not_hold(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.roles.manage']);
        $unheldPermission = Permission::query()->firstOrCreate(['name' => 'payroll.post']);
        $role = Role::factory()->for($company)->create();
        $role->permissions()->attach($unheldPermission);

        $this->postJson("/api/v1/platform/roles/{$role->id}/clone", ['name' => 'Escalated clone'], ['X-Company-Id' => $company->id])
            ->assertForbidden()->assertJsonPath('error_code', 'PRIVILEGE_ESCALATION_DENIED');
        $this->assertDatabaseMissing('roles', ['company_id' => $company->id, 'name' => 'Escalated clone']);
    }

    public function test_system_role_cannot_be_archived(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.roles.manage']);
        $role = Role::factory()->for($company)->create(['is_system' => true]);

        $this->postJson("/api/v1/platform/roles/{$role->id}/archive", [], ['X-Company-Id' => $company->id])->assertConflict();
        $this->assertNull($role->fresh()->archived_at);
    }

    public function test_company_settings_update_audits_old_and_new_values(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.settings.manage', 'platform.settings.view']);

        $this->putJson('/api/v1/platform/settings', ['legal_name' => 'Updated Legal Name'], ['X-Company-Id' => $company->id])
            ->assertOk()->assertJsonPath('legal_name', 'Updated Legal Name');
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'name' => 'Updated Legal Name']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'settings_updated']);
    }

    public function test_base_currency_is_locked_after_financial_activity(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['platform.settings.manage', 'platform.settings.high-risk']);
        CompanySetting::factory()->for($company)->create(['legal_name' => $company->name, 'base_currency' => 'PKR']);
        Journal::factory()->for($company)->create(['created_by' => $user->id]);

        $this->putJson('/api/v1/platform/settings', ['base_currency' => 'USD'], ['X-Company-Id' => $company->id])
            ->assertConflict()->assertJsonPath('error_code', 'HISTORICAL_SETTING_LOCKED');
        $this->assertDatabaseHas('company_settings', ['company_id' => $company->id, 'base_currency' => 'PKR']);
    }

    public function test_fiscal_year_start_is_locked_after_fiscal_year_creation(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['platform.settings.manage', 'platform.settings.high-risk']);
        CompanySetting::factory()->for($company)->create(['legal_name' => $company->name, 'fiscal_year_start_month' => 7]);
        FiscalYear::factory()->for($company)->create(['created_by' => $user->id]);

        $this->putJson('/api/v1/platform/settings', ['fiscal_year_start_month' => 1], ['X-Company-Id' => $company->id])
            ->assertConflict()->assertJsonPath('error_code', 'HISTORICAL_SETTING_LOCKED');
    }
}
