<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CompanyInvitation;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_archived_never_used_custom_role_can_be_deleted_without_erasing_audit(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.roles.manage']);
        $role = Role::factory()->for($company)->create(['archived_at' => now()]);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'crm.view']));
        AuditLog::factory()->for($company)->create(['entity_type' => Role::class, 'entity_id' => (string) $role->id,
            'action' => 'role_archived', 'old_values' => ['name' => $role->name]]);
        AuditLog::factory()->for($company)->create(['entity_type' => Role::class, 'entity_id' => (string) $role->id,
            'action' => 'role_updated', 'old_values' => ['permissions' => [['pivot' => ['role_id' => $role->id]]]]]);

        $this->deleteJson("/api/v1/platform/roles/{$role->id}", [], ['X-Company-Id' => $company->id])
            ->assertOk()->assertJsonPath('message', 'Archived custom role deleted.');

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'entity_id' => (string) $role->id, 'action' => 'role_archived']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'entity_id' => (string) $role->id, 'action' => 'role_updated']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'entity_id' => (string) $role->id, 'action' => 'role_deleted']);
        $this->assertDatabaseMissing('permission_role', ['role_id' => $role->id]);
    }

    public function test_active_and_system_roles_cannot_be_deleted(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.roles.manage']);
        $active = Role::factory()->for($company)->create();
        $system = Role::factory()->for($company)->create(['name' => 'Admin', 'is_system' => true, 'archived_at' => now()]);

        foreach ([$active, $system] as $role) {
            $this->deleteJson("/api/v1/platform/roles/{$role->id}", [], ['X-Company-Id' => $company->id])
                ->assertConflict()->assertJsonPath('error_code', 'ROLE_DELETE_BLOCKED');
            $this->assertModelExists($role);
        }
    }

    public function test_active_inactive_and_secondary_memberships_block_deletion(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.roles.manage']);
        foreach ([true, false] as $active) {
            $role = Role::factory()->for($company)->create(['archived_at' => now()]);
            $membership = CompanyUser::factory()->for($company)->create(['role_id' => $role->id, 'is_active' => $active]);

            $this->deleteJson("/api/v1/platform/roles/{$role->id}", [], ['X-Company-Id' => $company->id])
                ->assertConflict()->assertJsonPath('error_code', 'ROLE_DELETE_BLOCKED');
            $this->assertSame($role->id, $membership->fresh()->role_id);
        }

        $secondary = Role::factory()->for($company)->create(['archived_at' => now()]);
        $membership = CompanyUser::factory()->for($company)->create(['role_id' => null, 'is_active' => false]);
        $membership->roles()->attach($secondary->id);

        $this->deleteJson("/api/v1/platform/roles/{$secondary->id}", [], ['X-Company-Id' => $company->id])
            ->assertConflict()->assertJsonPath('error_code', 'ROLE_DELETE_BLOCKED');
        $this->assertDatabaseHas('company_user_role', ['company_user_id' => $membership->id, 'role_id' => $secondary->id]);
    }

    public function test_every_invitation_status_blocks_deletion(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.roles.manage']);
        foreach (['PENDING', 'ACCEPTED', 'REVOKED', 'EXPIRED'] as $status) {
            $role = Role::factory()->for($company)->create(['archived_at' => now()]);
            CompanyInvitation::factory()->for($company)->create(['status' => $status, 'role_ids' => [$role->id]]);

            $this->deleteJson("/api/v1/platform/roles/{$role->id}", [], ['X-Company-Id' => $company->id])
                ->assertConflict()->assertJsonPath('error_code', 'ROLE_DELETE_BLOCKED');
            $this->assertModelExists($role);
        }
    }

    public function test_historical_assignment_audit_blocks_deletion_after_membership_removal(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.roles.manage']);
        $role = Role::factory()->for($company)->create(['archived_at' => now()]);
        AuditLog::factory()->for($company)->create(['action' => 'roles_updated', 'old_values' => ['roles' => [['id' => $role->id]]]]);

        $this->deleteJson("/api/v1/platform/roles/{$role->id}", [], ['X-Company-Id' => $company->id])
            ->assertConflict()->assertJsonPath('error_code', 'ROLE_DELETE_BLOCKED');

        $this->assertModelExists($role);
    }

    public function test_invitation_cannot_assign_role_archived_after_issuance(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.roles.manage', 'platform.invitations.manage']);
        $role = Role::factory()->for($company)->create();
        $invited = User::factory()->create();
        $created = $this->postJson('/api/v1/platform/invitations', ['email' => $invited->email, 'role_ids' => [$role->id]],
            ['X-Company-Id' => $company->id])->assertCreated();
        $this->postJson("/api/v1/platform/roles/{$role->id}/archive", [], ['X-Company-Id' => $company->id])->assertOk();
        Sanctum::actingAs($invited);

        $this->postJson('/api/v1/invitations/accept', ['token' => $created->json('acceptance_token')])
            ->assertConflict()->assertJsonPath('error_code', 'INVITATION_ROLE_UNAVAILABLE');

        $this->assertDatabaseMissing('company_users', ['company_id' => $company->id, 'user_id' => $invited->id]);
        $this->assertDatabaseHas('company_invitations', ['id' => $created->json('invitation.id'), 'status' => 'PENDING']);
    }

    public function test_invitation_cannot_assign_missing_or_foreign_role(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.roles.manage']);
        $invited = User::factory()->create();
        $foreign = Role::factory()->create();
        foreach ([999999, $foreign->id] as $roleId) {
            $token = str_repeat((string) $roleId, 64);
            $token = substr($token, 0, 64);
            $invitation = CompanyInvitation::factory()->for($company)->create(['email' => $invited->email,
                'token_hash' => hash('sha256', $token), 'role_ids' => [$roleId]]);
            Sanctum::actingAs($invited);

            $this->postJson('/api/v1/invitations/accept', ['token' => $token])
                ->assertConflict()->assertJsonPath('error_code', 'INVITATION_ROLE_UNAVAILABLE');

            $this->assertDatabaseHas('company_invitations', ['id' => $invitation->id, 'status' => 'PENDING']);
            $this->assertDatabaseMissing('company_users', ['company_id' => $company->id, 'user_id' => $invited->id]);
        }
    }

    public function test_system_role_cannot_be_modified_through_role_update(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.roles.manage']);
        $system = Role::factory()->for($company)->create(['name' => 'Admin', 'is_system' => true]);

        $this->putJson("/api/v1/platform/roles/{$system->id}", ['name' => 'Renamed', 'permission_ids' => []],
            ['X-Company-Id' => $company->id])->assertConflict();

        $this->assertSame('Admin', $system->fresh()->name);
        $this->assertTrue($system->fresh()->is_system);
    }

    public function test_foreign_company_and_unprivileged_user_cannot_delete_role(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.roles.manage']);
        $foreign = Role::factory()->create(['archived_at' => now()]);

        $this->deleteJson("/api/v1/platform/roles/{$foreign->id}", [], ['X-Company-Id' => $company->id])->assertNotFound();
        $this->assertModelExists($foreign);
        [, $viewerCompany] = $this->actingAsCompanyUser(['platform.roles.view']);
        $role = Role::factory()->for($viewerCompany)->create(['archived_at' => now()]);

        $this->deleteJson("/api/v1/platform/roles/{$role->id}", [], ['X-Company-Id' => $viewerCompany->id])
            ->assertForbidden()->assertJsonPath('error_code', 'PERMISSION_DENIED');
        $this->assertModelExists($role);
    }

    public function test_archiving_unassigned_custom_role_remains_available_but_system_role_is_protected(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.roles.manage']);
        $custom = Role::factory()->for($company)->create();
        $system = Role::factory()->for($company)->create(['name' => 'Admin', 'is_system' => true]);

        $this->postJson("/api/v1/platform/roles/{$custom->id}/archive", [], ['X-Company-Id' => $company->id])->assertOk();
        $this->postJson("/api/v1/platform/roles/{$system->id}/archive", [], ['X-Company-Id' => $company->id])->assertConflict();

        $this->assertNotNull($custom->fresh()->archived_at);
        $this->assertNull($system->fresh()->archived_at);
    }
}
