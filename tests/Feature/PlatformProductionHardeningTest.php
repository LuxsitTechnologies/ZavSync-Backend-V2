<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\PlatformModule;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PlatformProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_payload_exposes_company_specific_roles_permissions_and_modules(): void
    {
        [, $company] = $this->actingAsCompanyUser(['crm.view']);

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('companies.0.id', $company->id)
            ->assertJsonPath('companies.0.permissions.0', 'crm.view')
            ->assertJson(fn ($json) => $json->has('companies.0.roles')->has('companies.0.modules')->etc());
    }

    public function test_company_switch_validates_active_membership(): void
    {
        [, $company] = $this->actingAsCompanyUser([]);
        $foreign = Company::factory()->create();

        $this->postJson('/api/v1/auth/switch-company', ['company_id' => $company->id])->assertOk()->assertJsonPath('company.id', $company->id);
        $this->postJson('/api/v1/auth/switch-company', ['company_id' => $foreign->id])->assertForbidden();
    }

    public function test_missing_and_foreign_company_context_return_structured_errors(): void
    {
        [, $company] = $this->actingAsCompanyUser([]);

        $this->getJson('/api/v1/platform/notifications')->assertUnprocessable()->assertJsonPath('error_code', 'COMPANY_CONTEXT_REQUIRED');
        $this->getJson('/api/v1/platform/notifications', ['X-Company-Id' => Company::factory()->create()->id])
            ->assertForbidden()->assertJsonPath('error_code', 'TENANT_ACCESS_DENIED');
        $this->assertNotEmpty($company->id);
    }

    public function test_invitation_cleanup_command_expires_only_overdue_pending_records(): void
    {
        $expired = CompanyInvitation::factory()->create(['status' => 'PENDING', 'expires_at' => now()->subMinute()]);
        $future = CompanyInvitation::factory()->create(['status' => 'PENDING', 'expires_at' => now()->addDay()]);

        $this->artisan('platform:expire-invitations')->assertSuccessful();
        $this->assertSame('EXPIRED', $expired->fresh()->status);
        $this->assertSame('PENDING', $future->fresh()->status);
    }

    public function test_plan_prices_reject_floating_point_values(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['platform.plans.manage']);
        $user->forceFill(['is_platform_admin' => true])->save();
        PlatformModule::factory()->create(['key' => 'crm']);

        $this->postJson('/api/v1/platform/plans', [
            'code' => 'floating', 'name' => 'Floating', 'price_minor' => 10.25, 'currency' => 'PKR',
            'billing_interval' => 'monthly', 'module_keys' => ['crm'],
        ], ['X-Company-Id' => $company->id])->assertUnprocessable()->assertJsonValidationErrors('price_minor');
    }

    public function test_company_permission_cache_does_not_leak_between_companies(): void
    {
        [$user, $companyA] = $this->actingAsCompanyUser(['platform.settings.view']);
        $companyB = Company::factory()->create();
        $role = Role::factory()->for($companyB)->create();
        CompanyUser::factory()->for($companyB)->for($user)->create(['role_id' => $role->id]);

        $this->getJson('/api/v1/platform/settings', ['X-Company-Id' => $companyA->id])->assertOk();
        $this->getJson('/api/v1/platform/settings', ['X-Company-Id' => $companyB->id])->assertForbidden()->assertJsonPath('error_code', 'PERMISSION_DENIED');
    }

    public function test_administration_actions_never_create_journals(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.settings.manage']);
        $this->putJson('/api/v1/platform/settings', ['legal_name' => 'No Journal Company'], ['X-Company-Id' => $company->id])->assertOk();

        $this->assertDatabaseCount('journals', 0);
    }

    public function test_authenticated_user_can_create_isolated_trial_company(): void
    {
        [$user] = $this->actingAsCompanyUser([]);
        Permission::factory()->create(['name' => 'platform.settings.view']);
        $plan = Plan::factory()->create();

        $response = $this->postJson('/api/v1/platform/companies', [
            'name' => 'New Tenant', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi', 'country_code' => 'PK', 'plan_id' => $plan->id,
        ])->assertCreated()->assertJsonPath('company.name', 'New Tenant');
        $companyId = $response->json('company.id');
        $this->assertDatabaseHas('company_users', ['company_id' => $companyId, 'user_id' => $user->id, 'is_active' => true]);
        $this->assertDatabaseHas('subscriptions', ['company_id' => $companyId, 'plan_id' => $plan->id, 'status' => 'TRIALING']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $companyId, 'action' => 'company_created']);
    }

    public function test_login_endpoint_is_rate_limited_after_repeated_failures(): void
    {
        RateLimiter::clear('127.0.0.1|limited@example.com');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'limited@example.com', 'password' => 'wrong-password'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'limited@example.com', 'password' => 'wrong-password'])->assertTooManyRequests();
    }

    public function test_password_reset_request_is_enumeration_safe_queued_and_rate_limited(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()
                ->assertJsonMissingPath('user')->assertJsonMissingPath('token');
        }
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertTooManyRequests();

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification): bool {
            return Crypt::decryptString($notification->encryptedToken) !== '';
        });
    }

    public function test_valid_password_reset_revokes_sessions_and_records_security_event(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $token = Password::createToken($user);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Browser', 'payload' => '{}', 'last_activity' => now()->timestamp]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $token, 'password' => 'New-password-123', 'password_confirmation' => 'New-password-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('New-password-123', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => 'PASSWORD_RESET', 'result' => 'SUCCESS']);
    }
}
