<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicCompanyOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_signup_requires_email_verification_before_company_creation(): void
    {
        Notification::fake();
        $plan = Plan::factory()->create(['code' => 'starter']);
        $this->getJson('/api/v1/auth/onboarding/plans')->assertOk()
            ->assertJsonPath('plans.0.id', $plan->id)->assertJsonPath('checkout_available', false)
            ->assertJsonMissingPath('plans.0.price_minor');
        $registration = ['name' => 'New Founder', 'email' => 'FOUNDER@example.test', 'password' => 'Strong-password-123', 'password_confirmation' => 'Strong-password-123'];
        $this->postJson('/api/v1/auth/register', $registration)->assertStatus(202)
            ->assertJsonMissingPath('password')->assertJsonMissingPath('user');
        $user = User::query()->where('email', 'founder@example.test')->sole();
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->is_platform_admin);
        $registeredEvent = SecurityEvent::query()->where('user_id', $user->id)->where('type', 'PUBLIC_ACCOUNT_REGISTERED')->sole();
        $this->assertSame('PENDING_EMAIL_VERIFICATION', $registeredEvent->result);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->postJson('/api/v1/auth/register', $registration)->assertStatus(202);
        $this->assertSame(1, User::query()->where('email', $user->email)->count());
        $this->assertSame(1, SecurityEvent::query()->where('user_id', $user->id)->where('type', 'PUBLIC_ACCOUNT_REGISTERED')->count());
        $this->assertDatabaseCount('companies', 0);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.email_verified', false)->assertJsonCount(0, 'companies');
        $company = ['name' => 'Founder Company', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi', 'country_code' => 'PK', 'plan_id' => $plan->id];
        $this->postJson('/api/v1/platform/companies', $company)->assertForbidden()->assertJsonPath('error_code', 'EMAIL_VERIFICATION_REQUIRED');

        $signed = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->getJson($signed)->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => 'PUBLIC_EMAIL_VERIFIED', 'result' => 'SUCCESS']);
        Sanctum::actingAs($user->fresh());
        $id = $this->postJson('/api/v1/platform/companies', $company)->assertCreated()->json('company.id');
        $this->assertDatabaseHas('companies', ['id' => $id, 'approval_status' => 'PENDING']);
        $this->assertDatabaseHas('subscriptions', ['company_id' => $id, 'status' => 'PENDING_APPROVAL', 'starts_at' => null]);
        $this->assertDatabaseHas('company_users', ['company_id' => $id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('roles', ['company_id' => $id, 'name' => 'Admin', 'is_system' => true]);
        $this->assertDatabaseMissing('roles', ['company_id' => $id, 'name' => 'Company Administrator']);
        foreach (['billing.view', 'billing.manage', 'portal.settings.view', 'portal.settings.manage'] as $permission) {
            $this->assertTrue($user->hasCompanyPermission($id, $permission));
        }
        $this->assertFalse($user->hasCompanyPermission($id, 'platform.companies.approve'));
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('companies.0.is_company_owner', true)
            ->assertJsonPath('companies.0.employee_linked', false);
    }

    public function test_legacy_growth_plan_cannot_be_selected_for_new_commercial_onboarding(): void
    {
        $approved = Plan::factory()->create(['code' => 'starter']);
        $legacy = Plan::factory()->create(['code' => 'growth']);
        $this->getJson('/api/v1/auth/onboarding/plans')->assertOk()
            ->assertJsonCount(1, 'plans')->assertJsonPath('plans.0.id', $approved->id)
            ->assertJsonMissing(['id' => $legacy->id]);
        $founder = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($founder);

        $this->postJson('/api/v1/platform/companies', [
            'name' => 'Legacy Plan Rejected', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi',
            'country_code' => 'PK', 'plan_id' => $legacy->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('plan_id');
        $approved->update(['is_active' => false]);
        $this->postJson('/api/v1/platform/companies', [
            'name' => 'Inactive Plan Rejected', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi',
            'country_code' => 'PK', 'plan_id' => $approved->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('plan_id');
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_duplicate_registration_is_generic_and_invalid_verification_link_does_not_activate_identity(): void
    {
        Notification::fake();
        $existing = User::factory()->create(['email' => 'existing@example.test']);
        $data = ['name' => 'Attacker', 'email' => 'existing@example.test', 'password' => 'Strong-password-123', 'password_confirmation' => 'Strong-password-123'];
        $this->postJson('/api/v1/auth/register', $data)->assertStatus(202)
            ->assertJsonPath('message', 'If this address can register, a verification email has been sent.');
        Notification::assertNothingSent();
        $this->assertSame(1, User::query()->where('email', $existing->email)->count());
        $this->assertDatabaseMissing('security_events', ['type' => 'PUBLIC_ACCOUNT_REGISTERED', 'user_id' => $existing->id]);

        $pending = User::factory()->unverified()->create();
        $signed = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $pending->id, 'hash' => sha1($pending->email)]);
        $this->getJson($signed.'&tampered=1')->assertForbidden();
        $this->assertNull($pending->fresh()->email_verified_at);
        $this->assertSame(0, Company::query()->count());
    }

    public function test_security_event_result_column_preserves_each_current_result_value(): void
    {
        foreach (['SUCCESS', 'DENIED', 'PENDING_EMAIL_VERIFICATION'] as $result) {
            $event = SecurityEvent::factory()->create(['result' => $result]);

            $this->assertSame($result, $event->fresh()->result);
        }
    }
}
