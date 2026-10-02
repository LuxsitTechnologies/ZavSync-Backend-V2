<?php

namespace Tests\Feature\Stage16;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\InvitationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_employee_resolves_a_private_read_only_self_profile(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['employee.self.view', 'employee.links.manage']);
        $membership = $this->membership($company, $user);
        $employee = $this->employee($company, $user);

        $this->putJson($this->linkUrl($membership), ['employee_id' => $employee->id], $this->headers($company))->assertOk();
        $response = $this->getJson('/api/v1/employee/me', $this->headers($company))->assertOk()
            ->assertJsonPath('linked', true)->assertJsonPath('self_editable', false)
            ->assertJsonPath('employee.id', $employee->id)->assertJsonPath('employee.status', 'active');

        $this->assertSame(
            ['id', 'employee_code', 'full_name', 'email', 'phone', 'department', 'designation', 'employment_type', 'status', 'joining_date', 'leaving_date', 'location'],
            array_keys($response->json('employee')),
        );
        $this->assertStringNotContainsString('base_salary', $response->getContent());
        $this->assertStringNotContainsString('tax_identifier', $response->getContent());
        $this->assertStringNotContainsString('employee_bank_reference', $response->getContent());
        $this->assertNoFinancialEffects();
    }

    public function test_unlinked_membership_never_matches_an_employee_by_email(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['employee.self.view']);
        $this->employee($company, $user, ['email' => $user->email]);

        $this->getJson('/api/v1/employee/me', $this->headers($company))->assertOk()
            ->assertExactJson(['linked' => false, 'employee' => null, 'self_editable' => false]);
    }

    public function test_company_switch_resolves_terminated_identity_without_affecting_another_company(): void
    {
        [$user, $companyA] = $this->actingAsCompanyUser(['employee.self.view']);
        $companyB = Company::factory()->create();
        $companyC = Company::factory()->create();
        $employeeA = $this->employee($companyA, $user, ['status' => 'terminated', 'full_name' => 'Former Employee A']);
        $employeeB = $this->employee($companyB, $user, ['status' => 'active', 'full_name' => 'Current Employee B']);
        $this->membership($companyA, $user)->forceFill(['employee_id' => $employeeA->id])->save();
        $this->newMembership($companyB, $user, ['employee.self.view'])->forceFill(['employee_id' => $employeeB->id])->save();
        $this->newMembership($companyC, $user, ['employee.self.view']);

        $this->getJson('/api/v1/employee/me', $this->headers($companyA))->assertOk()
            ->assertJsonPath('employee.id', $employeeA->id)->assertJsonPath('employee.status', 'terminated')
            ->assertJsonPath('self_editable', false)->assertDontSee('Current Employee B');
        $this->postJson('/api/v1/auth/switch-company', ['company_id' => $companyB->id])->assertOk();
        $this->getJson('/api/v1/employee/me', $this->headers($companyB))->assertOk()
            ->assertJsonPath('employee.id', $employeeB->id)->assertJsonPath('employee.status', 'active')
            ->assertDontSee('Former Employee A');
        $this->getJson('/api/v1/employee/me', $this->headers($companyC))->assertOk()->assertJsonPath('linked', false);
        $this->assertTrue($this->membership($companyA, $user)->is_active);
        $this->assertTrue($this->membership($companyB, $user)->is_active);
        $this->assertTrue($user->fresh()->belongsToCompany($companyB->id));
    }

    public function test_resigned_employee_remains_readable_but_cannot_self_edit(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['employee.self.view']);
        $employee = $this->employee($company, $user, ['status' => 'resigned']);
        $this->membership($company, $user)->forceFill(['employee_id' => $employee->id])->save();

        $this->getJson('/api/v1/employee/me', $this->headers($company))->assertOk()
            ->assertJsonPath('employee.status', 'resigned')->assertJsonPath('self_editable', false);
        $this->patchJson('/api/v1/employee/me', ['phone' => '123'], $this->headers($company))->assertStatus(405);
        $this->assertSame($employee->phone, $employee->fresh()->phone);
    }

    public function test_link_and_unlink_are_audited_and_repeated_link_is_idempotent(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['employee.links.manage']);
        $membership = $this->membership($company, $user);
        $employee = $this->employee($company, $user);

        $this->putJson($this->linkUrl($membership), ['employee_id' => $employee->id], $this->headers($company))
            ->assertOk()->assertJsonPath('employee_id', $employee->id);
        $this->getJson($this->linkUrl($membership), $this->headers($company))
            ->assertOk()->assertJsonPath('linked', true)->assertJsonPath('employee_id', $employee->id);
        $this->putJson($this->linkUrl($membership), ['employee_id' => $employee->id], $this->headers($company))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'employee_identity_linked']);
        $this->assertDatabaseCount('audit_logs', 1);

        $this->deleteJson($this->linkUrl($membership), [], $this->headers($company))
            ->assertOk()->assertJsonPath('linked', false);
        $this->assertNull($membership->fresh()->employee_id);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'employee_identity_unlinked']);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertNoFinancialEffects();
    }

    public function test_link_rejects_reassignment_and_duplicate_employee_ownership(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['employee.links.manage']);
        $membership = $this->membership($company, $user);
        $first = $this->employee($company, $user);
        $second = $this->employee($company, $user);
        $otherMembership = $this->newMembership($company, User::factory()->create(), []);
        $membership->forceFill(['employee_id' => $first->id])->save();

        $this->putJson($this->linkUrl($membership), ['employee_id' => $second->id], $this->headers($company))->assertConflict();
        $this->putJson($this->linkUrl($otherMembership), ['employee_id' => $first->id], $this->headers($company))->assertConflict();
        $this->assertSame($first->id, $membership->fresh()->employee_id);
        $this->assertNull($otherMembership->fresh()->employee_id);
    }

    public function test_cross_company_employee_and_membership_identifiers_are_rejected(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['employee.links.manage']);
        $other = Company::factory()->create();
        $membership = $this->membership($company, $user);
        $foreignEmployee = $this->employee($other, $user);
        $foreignMembership = $this->newMembership($other, User::factory()->create(), []);

        $this->putJson($this->linkUrl($membership), ['employee_id' => $foreignEmployee->id], $this->headers($company))->assertNotFound();
        $this->putJson($this->linkUrl($foreignMembership), ['employee_id' => $foreignEmployee->id], $this->headers($company))->assertNotFound();
        $this->deleteJson($this->linkUrl($foreignMembership), [], $this->headers($company))->assertNotFound();
        $this->assertNull($membership->fresh()->employee_id);
    }

    public function test_database_enforces_company_scope_and_employee_uniqueness(): void
    {
        [$user, $company] = $this->actingAsCompanyUser([]);
        $other = Company::factory()->create();
        $membership = $this->membership($company, $user);
        $foreignEmployee = $this->employee($other, $user);

        try {
            DB::table('company_users')->where('id', $membership->id)->update(['employee_id' => $foreignEmployee->id]);
            $this->fail('The company-scoped foreign key must reject a foreign employee.');
        } catch (QueryException) {
            $this->assertNull($membership->fresh()->employee_id);
        }

        $localEmployee = $this->employee($company, $user);
        $membership->forceFill(['employee_id' => $localEmployee->id])->save();
        $second = $this->newMembership($company, User::factory()->create(), []);
        try {
            DB::table('company_users')->where('id', $second->id)->update(['employee_id' => $localEmployee->id]);
            $this->fail('The unique employee link must reject a second membership.');
        } catch (QueryException) {
            $this->assertNull($second->fresh()->employee_id);
        }
    }

    public function test_platform_admin_without_own_permission_or_link_has_no_employee_identity(): void
    {
        [$user, $company] = $this->actingAsCompanyUser([]);
        $user->update(['is_platform_admin' => true]);
        $this->employee($company, $user);

        $this->getJson('/api/v1/employee/me', $this->headers($company))->assertForbidden();
        $other = Company::factory()->create();
        $this->getJson('/api/v1/employee/me', $this->headers($other))->assertForbidden();
    }

    public function test_link_administration_requires_explicit_permission(): void
    {
        [$user, $company] = $this->actingAsCompanyUser([]);
        $membership = $this->membership($company, $user);
        $employee = $this->employee($company, $user);

        $this->putJson($this->linkUrl($membership), ['employee_id' => $employee->id], $this->headers($company))->assertForbidden();
        $this->deleteJson($this->linkUrl($membership), [], $this->headers($company))->assertForbidden();
        $this->assertNull($membership->fresh()->employee_id);
    }

    public function test_inactive_membership_cannot_be_newly_linked(): void
    {
        [$administrator, $company] = $this->actingAsCompanyUser(['employee.links.manage']);
        $target = $this->newMembership($company, User::factory()->create(), []);
        $target->update(['is_active' => false]);
        $employee = $this->employee($company, $administrator);

        $this->putJson($this->linkUrl($target), ['employee_id' => $employee->id], $this->headers($company))->assertConflict();
        $this->assertNull($target->fresh()->employee_id);
    }

    public function test_platform_admin_with_self_view_but_no_employee_link_gets_unlinked_state(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['employee.self.view']);
        $user->update(['is_platform_admin' => true]);

        $this->getJson('/api/v1/employee/me', $this->headers($company))->assertOk()
            ->assertExactJson(['linked' => false, 'employee' => null, 'self_editable' => false]);
    }

    public function test_invitation_acceptance_and_existing_employee_email_do_not_create_a_link(): void
    {
        [$inviter, $company] = $this->actingAsCompanyUser([]);
        $invitedUser = User::factory()->create();
        $this->employee($company, $inviter, ['email' => $invitedUser->email]);
        $role = Role::factory()->for($company)->create();
        $invitation = app(InvitationService::class)->invite($company->id, $invitedUser->email, [$role->id], $inviter);
        app(InvitationService::class)->accept($invitation['token'], $invitedUser, null, null);
        $membership = $this->membership($company, $invitedUser);

        $this->assertNull($membership->employee_id);
        $this->assertNull($this->membership($company, $inviter)->employee_id);
    }

    public function test_account_profile_exposes_only_approved_fields_and_updates_only_name(): void
    {
        [$user] = $this->actingAsCompanyUser([]);

        $response = $this->getJson('/api/v1/auth/profile')->assertOk();
        $this->assertSame(['id', 'name', 'email', 'email_verified'], array_keys($response->json()));
        $this->patchJson('/api/v1/auth/profile', ['name' => 'New Account Name'])->assertOk()->assertJsonPath('name', 'New Account Name');
        $this->assertSame('New Account Name', $user->fresh()->name);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => 'ACCOUNT_PROFILE_UPDATED']);
    }

    public function test_account_profile_rejects_email_and_privilege_changes(): void
    {
        [$user] = $this->actingAsCompanyUser([]);
        $originalEmail = $user->email;

        $this->patchJson('/api/v1/auth/profile', ['name' => 'Ignored', 'email' => 'other@example.com'])->assertUnprocessable();
        $this->patchJson('/api/v1/auth/profile', ['name' => 'Ignored', 'is_platform_admin' => true])->assertUnprocessable();
        $this->assertSame($originalEmail, $user->fresh()->email);
        $this->assertFalse($user->fresh()->is_platform_admin);
        $this->assertNotSame('Ignored', $user->fresh()->name);
    }

    public function test_password_change_rejects_wrong_current_password_and_short_new_password(): void
    {
        [$user] = $this->actingAsCompanyUser([]);

        $this->postJson('/api/v1/auth/change-password', ['current_password' => 'wrong', 'password' => 'New-strong-password-123', 'password_confirmation' => 'New-strong-password-123'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson('/api/v1/auth/change-password', ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => 'PASSWORD_CHANGE_REJECTED']);
    }

    public function test_password_change_revokes_tokens_and_database_sessions_without_logging_secrets(): void
    {
        [$user] = $this->actingAsCompanyUser([]);
        $user->createToken('existing');
        config()->set('session.driver', 'database');
        DB::table('sessions')->insert(['id' => 'another-session', 'user_id' => $user->id, 'payload' => 'fixture', 'last_activity' => time()]);
        $newPassword = 'New-strong-password-123';

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password', 'password' => $newPassword, 'password_confirmation' => $newPassword,
        ])->assertOk();

        $this->assertTrue(Hash::check($newPassword, $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('sessions', ['id' => 'another-session']);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => 'PASSWORD_CHANGED']);
        $this->assertStringNotContainsString($newPassword, $response->getContent());
        $this->assertStringNotContainsString($newPassword, (string) DB::table('security_events')->first()->metadata);
    }

    public function test_anonymous_profile_request_is_rejected(): void
    {
        $this->getJson('/api/v1/auth/profile')->assertUnauthorized();
    }

    private function employee(Company $company, User $creator, array $attributes = []): Employee
    {
        return Employee::factory()->for($company)->create([...$attributes, 'created_by' => $creator->id]);
    }

    private function membership(Company $company, User $user): CompanyUser
    {
        return CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail();
    }

    /** @param array<int, string> $permissions */
    private function newMembership(Company $company, User $user, array $permissions): CompanyUser
    {
        $role = Role::factory()->for($company)->create();
        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $name]));
        }

        return CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role_id' => $role->id, 'is_active' => true]);
    }

    /** @return array<string, string> */
    private function headers(Company $company): array
    {
        return ['X-Company-Id' => $company->id];
    }

    private function linkUrl(CompanyUser $membership): string
    {
        return "/api/v1/platform/users/{$membership->id}/employee-link";
    }

    private function assertNoFinancialEffects(): void
    {
        foreach (['invoices', 'pakistan_fbr_invoices', 'journals', 'journal_lines', 'customer_payments', 'inventory_movements', 'bank_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
