<?php

namespace Tests\Feature;

use App\Exceptions\PlatformException;
use App\Jobs\SendCompanyInvitation;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\CompanyInvitation;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\PlatformModule;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformInvitationEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_can_be_created_and_existing_user_accepts_it_once(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.invitations.manage']);
        $role = Role::factory()->for($company)->create();
        $invited = User::factory()->create(['email' => 'invitee@example.com']);
        $created = $this->postJson('/api/v1/platform/invitations', ['email' => $invited->email, 'role_ids' => [$role->id]], ['X-Company-Id' => $company->id])
            ->assertCreated()->assertJsonPath('invitation.status', 'PENDING');
        $token = $created->json('acceptance_token');
        Sanctum::actingAs($invited);

        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk()->assertJsonPath('already_accepted', false);
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk()->assertJsonPath('already_accepted', true);
        $membership = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $invited->id)->firstOrFail();
        $this->assertTrue($membership->roles()->whereKey($role->id)->exists());
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'user_id' => $invited->id, 'action' => 'invitation_accepted']);
    }

    public function test_existing_identity_must_authenticate_before_accepting_invitation(): void
    {
        $company = Company::factory()->create();
        $inviter = User::factory()->create();
        $role = Role::factory()->for($company)->create();
        User::factory()->create(['email' => 'existing@example.com']);
        $result = app(InvitationService::class)->invite($company->id, 'existing@example.com', [$role->id], $inviter);

        try {
            app(InvitationService::class)->accept($result['token'], null, null, null);
            $this->fail('An existing identity must authenticate before invitation acceptance.');
        } catch (PlatformException $exception) {
            $this->assertSame('INVITATION_AUTHENTICATION_REQUIRED', $exception->errorCode);
        }
        $this->assertDatabaseMissing('company_users', ['company_id' => $company->id, 'user_id' => User::query()->where('email', 'existing@example.com')->value('id')]);
    }

    public function test_new_identity_can_register_through_a_valid_invitation(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.invitations.manage']);
        $role = Role::factory()->for($company)->create();
        $created = $this->postJson('/api/v1/platform/invitations', ['email' => 'new-user@example.com', 'role_ids' => [$role->id]], ['X-Company-Id' => $company->id])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/invitations/accept', [
            'token' => $created->json('acceptance_token'), 'name' => 'New User', 'password' => 'Strong-password-123',
        ])->assertOk()->assertJsonPath('already_accepted', false);

        $userId = User::query()->where('email', 'new-user@example.com')->value('id');
        $this->assertDatabaseHas('company_users', ['company_id' => $company->id, 'user_id' => $userId, 'is_active' => true]);
    }

    public function test_invitation_creation_requires_explicit_permission(): void
    {
        [, $company] = $this->actingAsCompanyUser([]);
        $role = Role::factory()->for($company)->create();

        $this->postJson('/api/v1/platform/invitations', ['email' => 'blocked@example.com', 'role_ids' => [$role->id]], ['X-Company-Id' => $company->id])
            ->assertForbidden()->assertJsonPath('error_code', 'PERMISSION_DENIED');
    }

    public function test_duplicate_active_invitation_is_rejected(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.invitations.manage']);
        $role = Role::factory()->for($company)->create();
        $payload = ['email' => 'duplicate@example.com', 'role_ids' => [$role->id]];
        $this->postJson('/api/v1/platform/invitations', $payload, ['X-Company-Id' => $company->id])->assertCreated();

        $this->postJson('/api/v1/platform/invitations', $payload, ['X-Company-Id' => $company->id])
            ->assertConflict()->assertJsonPath('error_code', 'INVITATION_ALREADY_PENDING');
    }

    public function test_invitation_email_is_queued_with_encrypted_token(): void
    {
        Queue::fake([SendCompanyInvitation::class]);
        [, $company] = $this->actingAsCompanyUser(['platform.invitations.manage']);
        $role = Role::factory()->for($company)->create();
        $response = $this->postJson('/api/v1/platform/invitations', ['email' => 'queued@example.com', 'role_ids' => [$role->id]], ['X-Company-Id' => $company->id])->assertCreated();
        $token = $response->json('acceptance_token');

        Queue::assertPushed(SendCompanyInvitation::class, fn (SendCompanyInvitation $job): bool => $job->invitationId === $response->json('invitation.id') && $job->encryptedToken !== $token);
    }

    public function test_expired_and_revoked_invitations_cannot_be_accepted(): void
    {
        $service = app(InvitationService::class);
        $expired = CompanyInvitation::factory()->create(['expires_at' => now()->subMinute()]);
        $revoked = CompanyInvitation::factory()->create(['status' => 'REVOKED', 'revoked_at' => now()]);

        foreach ([[$expired, 'INVITATION_EXPIRED'], [$revoked, 'INVITATION_REVOKED']] as [$invitation, $code]) {
            try {
                $service->accept('wrong-token', null, 'New User', 'strong-password');
                $this->fail('An invalid token must not be accepted.');
            } catch (PlatformException $exception) {
                $this->assertSame('INVITATION_INVALID', $exception->errorCode);
            }
            $token = 'known-token-'.$invitation->id;
            $invitation->update(['token_hash' => hash('sha256', $token)]);
            try {
                $service->accept($token, null, 'New User', 'strong-password');
                $this->fail('The invitation state must be enforced.');
            } catch (PlatformException $exception) {
                $this->assertSame($code, $exception->errorCode);
            }
        }
    }

    public function test_cross_company_role_cannot_be_used_for_invitation(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.invitations.manage']);
        $foreignRole = Role::factory()->create();

        $this->postJson('/api/v1/platform/invitations', ['email' => 'person@example.com', 'role_ids' => [$foreignRole->id]], ['X-Company-Id' => $company->id])
            ->assertForbidden()->assertJsonPath('error_code', 'TENANT_ACCESS_DENIED');
    }

    public function test_invitation_manager_cannot_invite_with_permissions_they_do_not_hold(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.invitations.manage']);
        $permission = Permission::query()->firstOrCreate(['name' => 'payroll.post']);
        $role = Role::factory()->for($company)->create();
        $role->permissions()->attach($permission);

        $this->postJson('/api/v1/platform/invitations', ['email' => 'escalated@example.com', 'role_ids' => [$role->id]], ['X-Company-Id' => $company->id])
            ->assertForbidden()->assertJsonPath('error_code', 'PRIVILEGE_ESCALATION_DENIED');
    }

    public function test_permission_and_entitlement_are_both_required(): void
    {
        [, $company] = $this->actingAsCompanyUser(['accounting.view']);
        $this->subscribe($company, ['crm']);

        $this->getJson('/api/v1/accounting/accounts', ['X-Company-Id' => $company->id])
            ->assertForbidden()->assertJsonPath('error_code', 'MODULE_NOT_ENTITLED');
        CompanyEntitlement::factory()->for($company)->create(['module_key' => 'accounting', 'is_enabled' => true]);
        app(EntitlementService::class)->forget($company->id);
        $this->getJson('/api/v1/accounting/accounts', ['X-Company-Id' => $company->id])->assertOk();

        [, $withoutPermission] = $this->actingAsCompanyUser([]);
        $this->subscribe($withoutPermission, ['accounting']);
        $this->getJson('/api/v1/accounting/accounts', ['X-Company-Id' => $withoutPermission->id])->assertForbidden();
    }

    public function test_company_entitlement_never_affects_another_company(): void
    {
        [$userA, $companyA] = $this->actingAsCompanyUser(['crm.view', 'crm.reports.view']);
        [$userB, $companyB] = $this->actingAsCompanyUser(['crm.view', 'crm.reports.view']);
        $this->subscribe($companyA, ['crm']);
        $this->subscribe($companyB, ['accounting']);

        Sanctum::actingAs($userA);
        $this->assertTrue($userA->hasCompanyPermission($companyA->id, 'crm.view'));
        $this->assertContains('crm', app(EntitlementService::class)->enabledModules($companyA->id));
        $this->getJson('/api/v1/crm/dashboard', ['X-Company-Id' => $companyA->id])->assertOk();
        Sanctum::actingAs($userB);
        $this->getJson('/api/v1/crm/dashboard', ['X-Company-Id' => $companyB->id])->assertForbidden()->assertJsonPath('error_code', 'MODULE_NOT_ENTITLED');
    }

    public function test_inactive_and_expired_trial_subscriptions_return_structured_error(): void
    {
        foreach ([['SUSPENDED', null], ['CANCELLED', null], ['TRIALING', now()->subDay()]] as [$status, $trialEnds]) {
            [, $company] = $this->actingAsCompanyUser(['crm.view']);
            $subscription = $this->subscribe($company, ['crm']);
            $subscription->update(['status' => $status, 'trial_ends_at' => $trialEnds]);
            app(EntitlementService::class)->forget($company->id);
            $this->getJson('/api/v1/crm/dashboard', ['X-Company-Id' => $company->id])
                ->assertForbidden()->assertJsonPath('error_code', 'SUBSCRIPTION_INACTIVE');
        }
    }

    public function test_user_usage_limit_blocks_new_invitation_without_deleting_members(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.invitations.manage']);
        $role = Role::factory()->for($company)->create();
        $plan = Plan::factory()->create(['usage_limits' => ['users' => 1]]);
        Subscription::factory()->for($company)->for($plan)->create();

        $this->postJson('/api/v1/platform/invitations', ['email' => 'limited@example.com', 'role_ids' => [$role->id]], ['X-Company-Id' => $company->id])
            ->assertConflict()->assertJsonPath('error_code', 'SUBSCRIPTION_LIMIT_REACHED');
        $this->assertDatabaseCount('company_users', 1);
    }

    public function test_unlimited_usage_and_plan_change_are_resolved_from_latest_subscription(): void
    {
        [, $company] = $this->actingAsCompanyUser([]);
        $unlimited = Plan::factory()->create(['usage_limits' => ['users' => -1]]);
        Subscription::factory()->for($company)->for($unlimited)->create(['starts_at' => now()->subDay()]);
        app(EntitlementService::class)->assertWithinLimit($company->id, 'users', 10_000);

        $limited = Plan::factory()->create(['usage_limits' => ['users' => 2]]);
        Subscription::factory()->for($company)->for($limited)->create(['starts_at' => now()]);

        $this->expectException(PlatformException::class);
        app(EntitlementService::class)->assertWithinLimit($company->id, 'users', 2);
    }

    public function test_subscription_scheduler_expires_elapsed_trials(): void
    {
        $subscription = Subscription::factory()->create(['status' => 'TRIALING', 'trial_ends_at' => now()->subMinute(), 'ends_at' => null]);

        $this->artisan('platform:evaluate-subscriptions')->assertSuccessful();

        $this->assertSame('EXPIRED', $subscription->fresh()->status);
    }

    /** @param array<int, string> $modules */
    private function subscribe(Company $company, array $modules): Subscription
    {
        foreach (array_unique([...$modules, 'accounting']) as $module) {
            PlatformModule::query()->firstOrCreate(['key' => $module], ['name' => ucfirst($module), 'is_active' => true]);
        }
        $plan = Plan::factory()->create();
        $plan->modules()->attach($modules, ['is_enabled' => true]);

        return Subscription::factory()->for($company)->for($plan)->create();
    }
}
