<?php

namespace Tests\Feature;

use App\Contracts\BillingGateway;
use App\Exceptions\PlatformException;
use App\Models\BillingCheckoutAttempt;
use App\Models\BillingComponentSubscription;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\FbrCompanyConfiguration;
use App\Models\FbrReferenceValue;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformCompanyAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_authorized_list_search_and_detail_are_bounded_and_do_not_expose_fbr_credentials(): void
    {
        [$administrator, $home] = $this->actingAsCompanyUser(['platform.companies.view']);
        $administrator->forceFill(['is_platform_admin' => true])->save();
        $this->migratePlatformPermissionsForTest($administrator);
        $target = Company::factory()->create(['name' => 'Acme Pakistan', 'approval_status' => 'PENDING']);
        CompanySetting::factory()->for($target)->create(['email' => 'acme@example.test', 'tax_identifier' => '1234567']);
        FbrCompanyConfiguration::factory()->for($target)->create(['credential' => 'never-return-this-secret', 'updated_by' => $administrator->id]);
        $headers = ['X-Company-Id' => $home->id];

        $this->getJson('/api/v1/platform/admin/companies?search=acme@example.test&per_page=1', $headers)
            ->assertOk()->assertJsonPath('data.0.id', $target->id)->assertJsonPath('data.0.fbr_credential_configured', true)
            ->assertJsonPath('total', 1)->assertDontSee('never-return-this-secret');
        $this->getJson("/api/v1/platform/admin/companies/{$target->id}", $headers)
            ->assertOk()->assertJsonPath('approval_status', 'PENDING')->assertJsonPath('email', 'acme@example.test')
            ->assertDontSee('never-return-this-secret');
        FbrCompanyConfiguration::query()->where('company_id', $target->id)->firstOrFail()->update(['credential' => null]);
        $this->getJson("/api/v1/platform/admin/companies/{$target->id}", $headers)
            ->assertOk()->assertJsonPath('fbr_credential_configured', false);
        $this->getJson('/api/v1/platform/admin/companies?per_page=101', $headers)->assertUnprocessable();
        $this->getJson('/api/v1/platform/admin/companies?approval_status=WRONG', $headers)->assertUnprocessable();
    }

    public function test_company_approval_and_activity_are_separate_idempotent_audited_transitions(): void
    {
        [$administrator, $home] = $this->actingAsCompanyUser(['platform.companies.approve', 'platform.companies.activate']);
        $administrator->forceFill(['is_platform_admin' => true])->save();
        $this->migratePlatformPermissionsForTest($administrator);
        $target = Company::factory()->create(['approval_status' => 'PENDING', 'is_active' => true]);
        CompanySetting::factory()->for($target)->create(['email' => 'approvable@example.test', 'tax_identifier' => '1234567']);
        $target->update(['province_code' => 'SINDH']);
        FbrReferenceValue::factory()->create(['category' => 'PROVINCE', 'code' => 'SINDH']);
        $headers = ['X-Company-Id' => $home->id];

        $this->postJson("/api/v1/platform/admin/companies/{$target->id}/approval", ['approval_status' => 'APPROVED', 'profile_version' => 1], $headers)
            ->assertOk()->assertJsonPath('approval_status', 'APPROVED')->assertJsonPath('is_active', true);
        $this->postJson("/api/v1/platform/admin/companies/{$target->id}/approval", ['approval_status' => 'APPROVED', 'profile_version' => 1], $headers)->assertOk();
        $this->patchJson("/api/v1/platform/admin/companies/{$target->id}/activity", ['is_active' => false, 'profile_version' => 2], $headers)
            ->assertOk()->assertJsonPath('approval_status', 'APPROVED')->assertJsonPath('is_active', false);
        $this->patchJson("/api/v1/platform/admin/companies/{$target->id}/activity", ['is_active' => true, 'profile_version' => 1], $headers)
            ->assertConflict()->assertJsonPath('error_code', 'COMPANY_PROFILE_VERSION_STALE');
        $this->patchJson("/api/v1/platform/admin/companies/{$home->id}/activity", ['is_active' => false, 'profile_version' => 1], $headers)
            ->assertConflict()->assertJsonPath('error_code', 'COMPANY_CONTEXT_DEACTIVATION_BLOCKED');
        $this->assertSame(3, $target->fresh()->profile_version);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_deactivation_schedules_paid_renewal_cancellation_before_disabling_company(): void
    {
        [$administrator, $home] = $this->actingAsCompanyUser(['platform.companies.activate']);
        $administrator->forceFill(['is_platform_admin' => true])->save();
        $this->migratePlatformPermissionsForTest($administrator);
        $target = Company::factory()->create(['approval_status' => 'APPROVED', 'is_active' => true]);
        $plan = Plan::factory()->create(['code' => 'starter']);
        $subscription = Subscription::factory()->for($target)->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_deactivateTest',
            'billing_currency' => 'PKR', 'renews_at' => now()->addMonth(),
        ]);
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $target->id, 'provider' => 'stripe',
            'provider_customer_id' => 'cus_deactivateTest', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->mock(BillingGateway::class)->shouldReceive('cancelAtPeriodEnd')->once()
            ->with('sub_deactivateTest', 'zavsync-cancel:'.$subscription->id)->andReturn([
                'id' => 'sub_deactivateTest', 'customer' => 'cus_deactivateTest', 'status' => 'active',
                'cancel_at_period_end' => true, 'current_period_end' => now()->addMonth()->timestamp,
            ]);
        $headers = ['X-Company-Id' => $home->id];
        $this->patchJson("/api/v1/platform/admin/companies/{$target->id}/activity", [
            'is_active' => false, 'profile_version' => 1,
        ], $headers)->assertOk()->assertJsonPath('is_active', false);
        $this->assertNotNull($subscription->fresh()->cancel_at_period_end_at);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $target->id, 'action' => 'billing_cancellation_scheduled']);
        $this->patchJson("/api/v1/platform/admin/companies/{$target->id}/activity", [
            'is_active' => false, 'profile_version' => 1,
        ], $headers)->assertOk()->assertJsonPath('is_active', false);
    }

    public function test_deactivation_fails_closed_when_provider_cannot_confirm_cancellation(): void
    {
        [$administrator, $home] = $this->actingAsCompanyUser(['platform.companies.activate']);
        $administrator->forceFill(['is_platform_admin' => true])->save();
        $this->migratePlatformPermissionsForTest($administrator);
        $target = Company::factory()->create(['approval_status' => 'APPROVED', 'is_active' => true]);
        $plan = Plan::factory()->create(['code' => 'starter']);
        $subscription = Subscription::factory()->for($target)->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_deactivateMismatch',
            'billing_currency' => 'PKR', 'renews_at' => now()->addMonth(),
        ]);
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $target->id, 'provider' => 'stripe',
            'provider_customer_id' => 'cus_deactivateCorrect', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->mock(BillingGateway::class)->shouldReceive('cancelAtPeriodEnd')->once()->andReturn([
            'id' => 'sub_deactivateMismatch', 'customer' => 'cus_anotherCompany', 'status' => 'active',
            'cancel_at_period_end' => true, 'current_period_end' => now()->addMonth()->timestamp,
        ]);
        $this->patchJson("/api/v1/platform/admin/companies/{$target->id}/activity", [
            'is_active' => false, 'profile_version' => 1,
        ], ['X-Company-Id' => $home->id])->assertConflict()->assertJsonPath('error_code', 'BILLING_PROVIDER_MISMATCH');
        $this->assertTrue($target->fresh()->is_active);
        $this->assertSame(1, $target->fresh()->profile_version);
        $this->assertNull($subscription->fresh()->cancel_at_period_end_at);
    }

    public function test_deactivation_cancels_independent_paid_components_before_disabling_company(): void
    {
        [$administrator, $home] = $this->actingAsCompanyUser(['platform.companies.activate']);
        $administrator->forceFill(['is_platform_admin' => true])->save();
        $this->migratePlatformPermissionsForTest($administrator);
        $target = Company::factory()->create(['approval_status' => 'APPROVED', 'is_active' => true]);
        $plan = Plan::factory()->create(['code' => 'starter']);
        $paidThrough = now()->addMonth()->startOfSecond();
        $subscription = Subscription::factory()->for($target)->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_deactivateComponents',
            'billing_currency' => 'PKR', 'renews_at' => $paidThrough,
        ]);
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $target->id, 'provider' => 'stripe',
            'provider_customer_id' => 'cus_deactivateComponents', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $attempt = BillingCheckoutAttempt::factory()->for($target)->create([
            'operation' => 'COMPONENT_CHECKOUT', 'status' => 'ACTIVE',
        ]);
        $component = BillingComponentSubscription::factory()->for($target)->create([
            'subscription_id' => $subscription->id, 'checkout_attempt_id' => $attempt->id,
            'provider_subscription_id' => 'sub_deactivateAddon', 'paid_through_at' => $paidThrough,
        ]);
        $gateway = $this->mock(BillingGateway::class);
        $gateway->shouldReceive('cancelAtPeriodEnd')->once()
            ->with('sub_deactivateAddon', 'zavsync-component-cancel:'.$component->id)->andReturn([
                'id' => 'sub_deactivateAddon', 'customer' => 'cus_deactivateComponents', 'status' => 'active',
                'cancel_at_period_end' => true, 'current_period_end' => $paidThrough->timestamp,
            ]);
        $gateway->shouldReceive('cancelAtPeriodEnd')->once()
            ->with('sub_deactivateComponents', 'zavsync-cancel:'.$subscription->id)->andReturn([
                'id' => 'sub_deactivateComponents', 'customer' => 'cus_deactivateComponents', 'status' => 'active',
                'cancel_at_period_end' => true, 'current_period_end' => $paidThrough->timestamp,
            ]);

        $this->patchJson("/api/v1/platform/admin/companies/{$target->id}/activity", [
            'is_active' => false, 'profile_version' => 1,
        ], ['X-Company-Id' => $home->id])->assertOk()->assertJsonPath('is_active', false);
        $this->assertNotNull($subscription->fresh()->cancel_at_period_end_at);
        $this->assertNotNull($component->fresh()->cancel_at_period_end_at);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $target->id, 'action' => 'billing_component_cancellation_scheduled',
        ]);
    }

    public function test_deactivation_fails_closed_while_component_checkout_is_unresolved(): void
    {
        [$administrator, $home] = $this->actingAsCompanyUser(['platform.companies.activate']);
        $administrator->forceFill(['is_platform_admin' => true])->save();
        $this->migratePlatformPermissionsForTest($administrator);
        $target = Company::factory()->create(['approval_status' => 'APPROVED', 'is_active' => true]);
        BillingCheckoutAttempt::factory()->for($target)->create([
            'operation' => 'COMPONENT_CHECKOUT', 'status' => 'PAYMENT_PENDING',
        ]);
        $this->mock(BillingGateway::class)->shouldNotReceive('cancelAtPeriodEnd');

        $this->patchJson("/api/v1/platform/admin/companies/{$target->id}/activity", [
            'is_active' => false, 'profile_version' => 1,
        ], ['X-Company-Id' => $home->id])->assertConflict()
            ->assertJsonPath('error_code', 'BILLING_CHANGE_REVIEW_REQUIRED');
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_deactivation_refuses_an_unresolved_past_due_subscription(): void
    {
        [$administrator, $home] = $this->actingAsCompanyUser(['platform.companies.activate']);
        $administrator->forceFill(['is_platform_admin' => true])->save();
        $this->migratePlatformPermissionsForTest($administrator);
        $target = Company::factory()->create(['approval_status' => 'APPROVED', 'is_active' => true]);
        $plan = Plan::factory()->create(['code' => 'starter']);
        Subscription::factory()->for($target)->for($plan)->create([
            'status' => 'PAST_DUE_GRACE', 'provider' => 'stripe', 'provider_reference' => 'sub_pastDue',
            'grace_ends_at' => now()->addDays(7), 'renews_at' => now()->addMonth(),
        ]);
        $this->mock(BillingGateway::class)->shouldNotReceive('cancelAtPeriodEnd');
        $this->patchJson("/api/v1/platform/admin/companies/{$target->id}/activity", [
            'is_active' => false, 'profile_version' => 1,
        ], ['X-Company-Id' => $home->id])->assertConflict()
            ->assertJsonPath('error_code', 'BILLING_SUBSCRIPTION_REVIEW_REQUIRED');
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_approval_rejects_incomplete_legal_profile_without_starting_trial(): void
    {
        [$administrator, $home] = $this->actingAsCompanyUser(['platform.companies.approve']);
        $administrator->forceFill(['is_platform_admin' => true])->save();
        $this->migratePlatformPermissionsForTest($administrator);
        $target = Company::factory()->create(['approval_status' => 'PENDING']);
        Subscription::factory()->for($target)->create(['status' => 'PENDING_APPROVAL', 'starts_at' => null, 'trial_ends_at' => null]);
        $this->postJson("/api/v1/platform/admin/companies/{$target->id}/approval", [
            'approval_status' => 'APPROVED', 'profile_version' => 1,
        ], ['X-Company-Id' => $home->id])->assertConflict()->assertJsonPath('error_code', 'COMPANY_PROFILE_INCOMPLETE');
        $this->assertSame('PENDING', $target->fresh()->approval_status);
        $this->assertNull($target->subscriptions()->firstOrFail()->starts_at);
    }

    public function test_pending_founder_can_complete_legal_profile_without_workspace_access_before_approval(): void
    {
        [$founder] = $this->actingAsCompanyUser([]);
        foreach (['platform.settings.view', 'platform.settings.manage', 'platform.settings.high-risk'] as $permission) {
            Permission::factory()->create(['name' => $permission]);
        }
        $plan = Plan::factory()->create(['code' => 'starter']);
        FbrReferenceValue::factory()->create(['category' => 'PROVINCE', 'code' => 'SINDH']);
        $id = $this->postJson('/api/v1/platform/companies', [
            'name' => 'Incomplete Founder Company', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi',
            'country_code' => 'PK', 'plan_id' => $plan->id,
        ])->assertCreated()->json('company.id');
        $this->getJson('/api/v1/platform/company-profile', ['X-Company-Id' => $id])->assertForbidden();
        $this->getJson("/api/v1/platform/companies/{$id}/pending-profile")->assertOk()->assertJsonPath('approval_status', 'PENDING');
        $this->patchJson("/api/v1/platform/companies/{$id}/pending-profile", [
            'profile_version' => 1, 'email' => 'legal@example.test', 'tax_identifier' => '1234567', 'province_code' => 'SINDH',
        ])->assertOk()->assertJsonPath('profile_version', 2);
        $this->patchJson("/api/v1/platform/companies/{$id}/pending-profile", [
            'profile_version' => 1, 'email' => 'stale@example.test',
        ])->assertConflict()->assertJsonPath('error_code', 'COMPANY_PROFILE_VERSION_STALE');

        [$administrator, $home] = $this->actingAsCompanyUser(['platform.companies.approve']);
        $administrator->forceFill(['is_platform_admin' => true])->save();
        $this->migratePlatformPermissionsForTest($administrator);
        $this->getJson("/api/v1/platform/companies/{$id}/pending-profile")->assertNotFound();
        $this->postJson("/api/v1/platform/admin/companies/{$id}/approval", [
            'approval_status' => 'APPROVED', 'profile_version' => 2,
        ], ['X-Company-Id' => $home->id])->assertOk()->assertJsonPath('approval_status', 'APPROVED');
        Sanctum::actingAs($founder);
        $this->getJson("/api/v1/platform/companies/{$id}/pending-profile")->assertNotFound();
        $this->getJson('/api/v1/platform/company-profile', ['X-Company-Id' => $id])->assertOk();
    }

    public function test_non_platform_admin_and_unscoped_actor_cannot_administer_other_companies(): void
    {
        [$actor, $home] = $this->actingAsCompanyUser(['platform.companies.view', 'platform.companies.approve']);
        $target = Company::factory()->create(['approval_status' => 'PENDING']);
        $headers = ['X-Company-Id' => $home->id];

        $this->getJson('/api/v1/platform/admin/companies', $headers)->assertForbidden();
        $this->postJson("/api/v1/platform/admin/companies/{$target->id}/approval", ['approval_status' => 'APPROVED', 'profile_version' => 1], $headers)->assertForbidden();
        $actor->forceFill(['is_platform_admin' => true])->save();
        $this->getJson('/api/v1/platform/admin/companies', ['X-Company-Id' => $target->id])->assertForbidden();
        $this->assertSame('PENDING', $target->fresh()->approval_status);
    }

    public function test_platform_admin_target_profile_edit_requires_target_membership_and_target_settings_authority(): void
    {
        [$administrator, $home] = $this->actingAsCompanyUser(['platform.companies.view']);
        $administrator->forceFill(['is_platform_admin' => true])->save();
        $this->migratePlatformPermissionsForTest($administrator);
        $target = Company::factory()->create(['approval_status' => 'PENDING']);
        CompanySetting::factory()->for($target)->create();
        $path = "/api/v1/platform/admin/companies/{$target->id}";
        $headers = ['X-Company-Id' => $home->id];

        $this->patchJson($path, ['profile_version' => 1, 'name' => 'Blocked Target'], $headers)->assertForbidden();
        $role = Role::factory()->for($target)->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'platform.settings.manage']));
        CompanyUser::factory()->for($target)->for($administrator)->create(['role_id' => $role->id]);
        $this->patchJson($path, ['profile_version' => 1, 'name' => 'Authorized Target'], $headers)
            ->assertOk()->assertJsonPath('name', 'Authorized Target')->assertJsonPath('profile_version', 2);
        $this->patchJson($path, ['profile_version' => 2, 'tax_identifier' => '1234567'], $headers)
            ->assertForbidden()->assertJsonPath('error_code', 'PERMISSION_DENIED');
        $this->assertNull($target->settings()->firstOrFail()->tax_identifier);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $target->id, 'action' => 'company_profile_updated']);
    }

    public function test_selected_company_profile_needs_membership_and_settings_view_permission(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.settings.view']);
        $other = Company::factory()->create();

        $this->getJson('/api/v1/platform/company-profile', ['X-Company-Id' => $company->id])
            ->assertOk()->assertJsonPath('id', $company->id)->assertJsonPath('approval_status', 'APPROVED');
        $this->getJson('/api/v1/platform/company-profile', ['X-Company-Id' => $other->id])->assertForbidden();
    }

    public function test_profile_edit_is_company_scoped_versioned_and_preserves_separate_approval_status(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.settings.view', 'platform.settings.manage']);
        CompanySetting::factory()->for($company)->create(['email' => 'original@example.test', 'tax_identifier' => '1234567']);
        $company->update(['province_code' => 'SINDH']);
        FbrReferenceValue::factory()->create(['category' => 'PROVINCE', 'code' => 'SINDH']);
        $headers = ['X-Company-Id' => $company->id];

        $this->patchJson('/api/v1/platform/company-profile', [
            'profile_version' => 1, 'name' => 'Acme 3M', 'company_size' => '11-50',
            'province_code' => 'SINDH', 'email' => 'legal@example.test',
        ], $headers)->assertOk()->assertJsonPath('name', 'Acme 3M')->assertJsonPath('company_size', '11-50')
            ->assertJsonPath('profile_version', 2)->assertJsonPath('approval_status', 'APPROVED');
        $this->patchJson('/api/v1/platform/company-profile', ['profile_version' => 1, 'name' => 'Stale Name'], $headers)
            ->assertConflict()->assertJsonPath('error_code', 'COMPANY_PROFILE_VERSION_STALE');
        $this->patchJson('/api/v1/platform/company-profile', ['profile_version' => 2, 'province_code' => 'UNKNOWN'], $headers)
            ->assertConflict()->assertJsonPath('error_code', 'COMPANY_PROFILE_INCOMPLETE');
        $this->assertSame('Acme 3M', $company->fresh()->name);
        $this->assertSame('SINDH', $company->fresh()->province_code);
        $this->assertDatabaseHas('company_settings', ['company_id' => $company->id, 'email' => 'legal@example.test']);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_existing_company_settings_route_cannot_clear_approved_legal_identity(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.settings.view', 'platform.settings.manage', 'platform.settings.high-risk']);
        CompanySetting::factory()->for($company)->create(['email' => 'legal@example.test', 'tax_identifier' => '1234567']);
        $headers = ['X-Company-Id' => $company->id];

        $this->putJson('/api/v1/platform/settings', ['email' => null], $headers)
            ->assertConflict()->assertJsonPath('error_code', 'COMPANY_PROFILE_INCOMPLETE');
        $this->putJson('/api/v1/platform/settings', ['tax_identifier' => null], $headers)
            ->assertConflict()->assertJsonPath('error_code', 'COMPANY_PROFILE_INCOMPLETE');
        $this->assertDatabaseHas('company_settings', [
            'company_id' => $company->id, 'email' => 'legal@example.test', 'tax_identifier' => '1234567',
        ]);
    }

    public function test_company_creation_is_pending_and_replays_only_the_same_actor_payload(): void
    {
        [$actor] = $this->actingAsCompanyUser([]);
        Permission::factory()->create(['name' => 'platform.settings.view']);
        $plan = Plan::factory()->create(['code' => 'starter']);
        FbrReferenceValue::factory()->create(['category' => 'PROVINCE', 'code' => 'SINDH']);
        $payload = [
            'name' => 'New Pending Company', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi',
            'country_code' => 'PK', 'plan_id' => $plan->id, 'email' => 'company@example.test',
            'company_size' => '11-50', 'province_code' => 'SINDH', 'tax_identifier' => '1234567',
        ];
        $headers = ['Idempotency-Key' => 'company-create-A'];

        $id = $this->postJson('/api/v1/platform/companies', $payload, $headers)->assertCreated()
            ->assertJsonPath('company.approval_status', 'PENDING')->assertJsonPath('trial_ends_at', null)
            ->assertJsonMissingPath('company.creation_idempotency_hash')->json('company.id');
        $plan->update(['is_active' => false]);
        $this->postJson('/api/v1/platform/companies', $payload, $headers)->assertOk()->assertJsonPath('company.id', $id);
        $this->postJson('/api/v1/platform/companies', [...$payload, 'name' => 'Changed'], $headers)->assertConflict();
        $this->assertDatabaseHas('company_settings', ['company_id' => $id, 'email' => 'company@example.test']);
        $this->assertDatabaseHas('company_users', ['company_id' => $id, 'user_id' => $actor->id]);
        $this->assertDatabaseHas('subscriptions', ['company_id' => $id, 'status' => 'PENDING_APPROVAL', 'starts_at' => null, 'trial_ends_at' => null]);
        $this->getJson('/api/v1/platform/company-profile', ['X-Company-Id' => $id])
            ->assertForbidden()->assertJsonPath('error_code', 'COMPANY_NOT_APPROVED');
        $this->postJson('/api/v1/auth/switch-company', ['company_id' => $id])
            ->assertForbidden()->assertJsonPath('error_code', 'COMPANY_NOT_APPROVED');
        Company::query()->findOrFail($id)->update(['approval_status' => 'APPROVED']);
        $this->postJson('/api/v1/auth/switch-company', ['company_id' => $id])->assertOk();
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_optional_company_creation_fbr_token_is_encrypted_write_only_and_requires_verified_seller_fields(): void
    {
        [$actor] = $this->actingAsCompanyUser([]);
        $plan = Plan::factory()->create(['code' => 'starter']);
        FbrReferenceValue::factory()->create(['category' => 'PROVINCE', 'code' => 'SINDH']);
        $payload = [
            'name' => 'Seller Company', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi',
            'country_code' => 'PK', 'plan_id' => $plan->id, 'province_code' => 'SINDH',
            'tax_identifier' => '1234567', 'address' => 'Seller Karachi address',
            'fbr_environment' => 'SANDBOX', 'fbr_token' => 'sensitive-fixture-token',
        ];
        $response = $this->postJson('/api/v1/platform/companies', $payload, ['Idempotency-Key' => 'secure-company'])
            ->assertCreated()->assertDontSee('sensitive-fixture-token')->assertJsonMissingPath('company.fbr_token');
        $companyId = $response->json('company.id');
        $configuration = FbrCompanyConfiguration::query()->where('company_id', $companyId)->sole();
        $this->assertSame('sensitive-fixture-token', $configuration->credential);
        $this->assertNotSame('sensitive-fixture-token', DB::table('fbr_company_configurations')->where('company_id', $companyId)->value('credential'));
        $this->assertDatabaseMissing('audit_logs', ['company_id' => $companyId, 'action' => 'fbr_credential_logged']);
        $this->assertStringNotContainsString('sensitive-fixture-token', DB::table('audit_logs')->where('company_id', $companyId)->first()->new_values);
        $this->postJson('/api/v1/platform/companies', [
            ...$payload, 'name' => 'Invalid Seller', 'province_code' => 'UNKNOWN',
        ])->assertUnprocessable();
        $this->assertSame($actor->id, $configuration->updated_by);
    }

    public function test_first_approval_starts_trial_after_review_without_resetting_it_on_reapproval(): void
    {
        Queue::fake();
        [$administrator, $home] = $this->actingAsCompanyUser(['platform.companies.approve']);
        $administrator->forceFill(['is_platform_admin' => true])->save();
        $this->migratePlatformPermissionsForTest($administrator);
        $plan = Plan::factory()->create(['code' => 'starter']);
        FbrReferenceValue::factory()->create(['category' => 'PROVINCE', 'code' => 'SINDH']);
        $this->travelTo(now()->startOfSecond());
        $createdAt = now()->copy();
        $created = $this->postJson('/api/v1/platform/companies', [
            'name' => 'Review Delay Company', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi',
            'country_code' => 'PK', 'plan_id' => $plan->id, 'email' => 'review@example.test',
            'tax_identifier' => '1234567', 'province_code' => 'SINDH',
        ])->assertCreated();
        $companyId = $created->json('company.id');
        $this->assertNull($created->json('trial_ends_at'));

        $this->travel(7)->days();
        $approvedAt = now()->copy();
        $headers = ['X-Company-Id' => $home->id];
        $this->postJson("/api/v1/platform/admin/companies/{$companyId}/approval", ['approval_status' => 'APPROVED', 'profile_version' => 1], $headers)
            ->assertOk()->assertJsonPath('approval_status', 'APPROVED');
        $trial = Subscription::query()->where('company_id', $companyId)->sole();
        $this->assertTrue($trial->starts_at->equalTo($approvedAt));
        $this->assertTrue($trial->trial_ends_at->equalTo($approvedAt->copy()->addDays(14)));
        $this->assertFalse($trial->starts_at->equalTo($createdAt));
        $this->assertDatabaseHas('platform_notifications', [
            'company_id' => $companyId, 'recipient_id' => $administrator->id,
            'type' => 'platform.company.approval', 'title' => 'Company approved',
        ]);

        $this->postJson("/api/v1/platform/admin/companies/{$companyId}/approval", ['approval_status' => 'APPROVED', 'profile_version' => 1], $headers)->assertOk();
        $this->postJson("/api/v1/platform/admin/companies/{$companyId}/approval", ['approval_status' => 'REJECTED', 'profile_version' => 2], $headers)->assertOk();
        $this->travel(1)->days();
        $this->postJson("/api/v1/platform/admin/companies/{$companyId}/approval", ['approval_status' => 'APPROVED', 'profile_version' => 3], $headers)->assertOk();
        $this->assertSame(1, Subscription::query()->where('company_id', $companyId)->count());
        $this->assertTrue(Subscription::query()->where('company_id', $companyId)->sole()->starts_at->equalTo($approvedAt));
    }

    public function test_deactivated_company_cannot_be_selected_or_used_even_with_active_membership(): void
    {
        [$actor, $company] = $this->actingAsCompanyUser(['platform.settings.view']);
        $company->update(['is_active' => false]);

        $this->getJson('/api/v1/platform/company-profile', ['X-Company-Id' => $company->id])
            ->assertForbidden()->assertJsonPath('error_code', 'COMPANY_INACTIVE');
        $this->postJson('/api/v1/auth/switch-company', ['company_id' => $company->id])
            ->assertForbidden()->assertJsonPath('error_code', 'COMPANY_INACTIVE');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('companies.0.is_active', false);
        $this->assertTrue($actor->belongsToCompany($company->id));
    }

    public function test_company_deactivation_retains_tombstone_and_eloquent_deletion_is_refused(): void
    {
        [$administrator, $home] = $this->actingAsCompanyUser(['platform.companies.activate']);
        $administrator->forceFill(['is_platform_admin' => true])->save();
        $this->migratePlatformPermissionsForTest($administrator);
        $target = Company::factory()->create(['approval_status' => 'APPROVED']);
        CompanySetting::factory()->for($target)->create();
        $this->patchJson("/api/v1/platform/admin/companies/{$target->id}/activity", [
            'is_active' => false, 'profile_version' => 1,
        ], ['X-Company-Id' => $home->id])->assertOk()->assertJsonPath('is_active', false);
        $this->assertDatabaseHas('companies', ['id' => $target->id, 'is_active' => false]);
        $this->assertDatabaseHas('company_settings', ['company_id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $target->id, 'action' => 'company_activity_changed']);
        try {
            $target->delete();
            $this->fail('Company deletion unexpectedly succeeded.');
        } catch (PlatformException $exception) {
            $this->assertSame('COMPANY_DELETION_RETAINED', $exception->errorCode);
        }
        $this->assertDatabaseHas('companies', ['id' => $target->id]);
    }

    public function test_company_creation_idempotency_key_is_case_sensitive_and_rejects_trailing_space(): void
    {
        $this->actingAsCompanyUser([]);
        $plan = Plan::factory()->create(['code' => 'starter']);
        $payload = ['name' => 'Case-Sensitive Company', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi', 'country_code' => 'PK', 'plan_id' => $plan->id];

        $upper = $this->postJson('/api/v1/platform/companies', $payload, ['Idempotency-Key' => 'Retry-A'])->assertCreated()->json('company.id');
        $lower = $this->postJson('/api/v1/platform/companies', $payload, ['Idempotency-Key' => 'retry-a'])->assertCreated()->json('company.id');
        $this->assertNotSame($upper, $lower);
        $this->postJson('/api/v1/platform/companies', $payload, ['Idempotency-Key' => 'Retry-A'])->assertOk()->assertJsonPath('company.id', $upper);
        $this->postJson('/api/v1/platform/companies', $payload, ['Idempotency-Key' => 'retry-a'])->assertOk()->assertJsonPath('company.id', $lower);
        $this->postJson('/api/v1/platform/companies', $payload, ['Idempotency-Key' => 'Retry-A '])->assertUnprocessable();
        $this->assertDatabaseCount('companies', 3);
    }

    public function test_company_creation_rejects_nonmeaningful_name_and_accepts_unicode_letters(): void
    {
        $this->actingAsCompanyUser([]);
        $plan = Plan::factory()->create(['code' => 'starter']);
        $payload = ['name' => 'A', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi', 'country_code' => 'PK', 'plan_id' => $plan->id];

        $this->postJson('/api/v1/platform/companies', $payload)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/platform/companies', [...$payload, 'name' => '@@@'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $created = $this->postJson('/api/v1/platform/companies', [...$payload, 'name' => 'نئی کمپنی'])->assertCreated();
        $this->assertNotSame('', $created->json('company.slug'));
        $this->assertSame('نئی کمپنی', $created->json('company.name'));
    }
}
