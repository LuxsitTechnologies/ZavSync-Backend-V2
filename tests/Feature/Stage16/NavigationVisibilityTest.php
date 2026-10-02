<?php

namespace Tests\Feature\Stage16;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\CompanyNavigationPreference;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\PlatformModule;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\Platform\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_and_fbr_are_independently_visible_by_default(): void
    {
        [, $company] = $this->actingAsCompanyUser(['accounting.view', 'pakistan_fbr.view']);
        $this->subscribe($company, ['invoicing']);

        $response = $this->getJson('/api/v1/platform/navigation', ['X-Company-Id' => $company->id])->assertOk();
        $this->assertContains('accounting.invoices', $response->json('visible_keys'));
        $this->assertContains('fbr.invoicing', $response->json('visible_keys'));
        $response->assertJsonPath('items.1.presentation_visible', true);
        $this->assertSame('People', $response->json('items.0.group'));
        $this->assertSame('hrm.employees', $response->json('items.0.key'));
        $this->assertSame(1, $this->item($response->json(), 'fbr.invoicing')['order']);
        $this->assertDatabaseCount('company_navigation_preferences', 0);
    }

    public function test_fbr_can_be_hidden_without_hiding_accounting_or_changing_api_authorization(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['platform.settings.manage', 'accounting.view', 'pakistan_fbr.view']);
        $this->subscribe($company, ['invoicing']);

        $response = $this->putJson('/api/v1/platform/navigation/fbr.invoicing', ['is_visible' => false], ['X-Company-Id' => $company->id])->assertOk();

        $this->assertContains('accounting.invoices', $response->json('visible_keys'));
        $this->assertNotContains('fbr.invoicing', $response->json('visible_keys'));
        $this->assertSame('HIDDEN_BY_COMPANY', $this->item($response->json(), 'fbr.invoicing')['unavailable_reason']);
        $this->assertDatabaseHas('company_navigation_preferences', ['company_id' => $company->id, 'item_key' => 'fbr.invoicing', 'is_visible' => false, 'updated_by' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'navigation_visibility_updated']);
        $this->assertSame(
            ['item_key' => 'fbr.invoicing', 'is_visible' => false],
            AuditLog::query()->where('company_id', $company->id)->where('action', 'navigation_visibility_updated')->sole()->new_values,
        );
        $this->getJson('/api/v1/pakistan-fbr/invoices', ['X-Company-Id' => $company->id])->assertOk();
        $this->assertNoFinancialEffects();
    }

    public function test_accounting_can_be_hidden_without_hiding_fbr_and_reset_restores_default(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.settings.manage', 'accounting.view', 'pakistan_fbr.view']);
        $this->subscribe($company, ['invoicing']);

        $hidden = $this->putJson('/api/v1/platform/navigation/accounting.invoices', ['is_visible' => false], ['X-Company-Id' => $company->id])->assertOk();
        $this->assertNotContains('accounting.invoices', $hidden->json('visible_keys'));
        $this->assertContains('fbr.invoicing', $hidden->json('visible_keys'));
        $this->getJson('/api/v1/accounting/invoices', ['X-Company-Id' => $company->id])->assertOk();

        $restored = $this->deleteJson('/api/v1/platform/navigation/accounting.invoices', [], ['X-Company-Id' => $company->id])->assertOk();
        $this->assertContains('accounting.invoices', $restored->json('visible_keys'));
        $this->assertDatabaseCount('company_navigation_preferences', 0);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'navigation_visibility_reset']);
        $this->assertNoFinancialEffects();
    }

    public function test_show_override_cannot_grant_missing_permission_or_entitlement(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.settings.manage', 'accounting.view']);
        $this->subscribe($company, ['invoicing']);

        $shown = $this->putJson('/api/v1/platform/navigation/fbr.invoicing', ['is_visible' => true], ['X-Company-Id' => $company->id])->assertOk();
        $this->assertSame('NOT_AUTHORIZED', $this->item($shown->json(), 'fbr.invoicing')['unavailable_reason']);
        $this->assertNotContains('fbr.invoicing', $shown->json('visible_keys'));

        $companyWithoutEntitlement = $this->actingAsCompanyUser(['platform.settings.manage', 'accounting.view', 'pakistan_fbr.view'])[1];
        $this->subscribe($companyWithoutEntitlement, ['crm']);
        $unentitled = $this->putJson('/api/v1/platform/navigation/fbr.invoicing', ['is_visible' => true], ['X-Company-Id' => $companyWithoutEntitlement->id])->assertOk();
        $this->assertSame('NOT_ENTITLED', $this->item($unentitled->json(), 'fbr.invoicing')['unavailable_reason']);
        $this->assertSame('NOT_ENTITLED', $this->item($unentitled->json(), 'accounting.invoices')['unavailable_reason']);
    }

    public function test_fbr_configuration_permission_does_not_grant_fbr_invoice_navigation(): void
    {
        [, $company] = $this->actingAsCompanyUser(['fbr.configuration.view']);
        $this->subscribe($company, ['invoicing']);

        $response = $this->getJson('/api/v1/platform/navigation', ['X-Company-Id' => $company->id])->assertOk();

        $this->assertContains('fbr.configuration', $response->json('visible_keys'));
        $this->assertNotContains('fbr.invoicing', $response->json('visible_keys'));
        $this->assertNotContains('accounting.invoices', $response->json('visible_keys'));
    }

    public function test_inactive_or_missing_catalog_module_cannot_become_visible(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.settings.manage', 'accounting.view', 'pakistan_fbr.view']);
        $this->subscribe($company, ['invoicing']);
        PlatformModule::query()->whereKey('invoicing')->update(['is_active' => false]);

        $response = $this->putJson('/api/v1/platform/navigation/fbr.invoicing', ['is_visible' => true], ['X-Company-Id' => $company->id])->assertOk();
        $this->assertSame('PLATFORM_INACTIVE', $this->item($response->json(), 'fbr.invoicing')['unavailable_reason']);
        $this->assertSame('PLATFORM_INACTIVE', $this->item($response->json(), 'accounting.invoices')['unavailable_reason']);
        $this->assertContains('invoicing', app(EntitlementService::class)->enabledModules($company->id));
        PlatformModule::query()->whereKey('invoicing')->delete();
        $missing = $this->getJson('/api/v1/platform/navigation', ['X-Company-Id' => $company->id])->assertOk();
        $this->assertSame('PLATFORM_INACTIVE', $this->item($missing->json(), 'fbr.invoicing')['unavailable_reason']);
    }

    public function test_expired_subscription_and_company_override_affect_only_commercial_layer(): void
    {
        [, $company] = $this->actingAsCompanyUser(['accounting.view', 'pakistan_fbr.view']);
        $subscription = $this->subscribe($company, ['invoicing']);
        $subscription->update(['status' => 'SUSPENDED']);
        app(EntitlementService::class)->forget($company->id);

        $inactive = $this->getJson('/api/v1/platform/navigation', ['X-Company-Id' => $company->id])->assertOk();
        $this->assertSame('NOT_ENTITLED', $this->item($inactive->json(), 'fbr.invoicing')['unavailable_reason']);
        $subscription->update(['status' => 'ACTIVE']);
        CompanyEntitlement::factory()->for($company)->create(['module_key' => 'invoicing', 'is_enabled' => false]);
        app(EntitlementService::class)->forget($company->id);
        $disabled = $this->getJson('/api/v1/platform/navigation', ['X-Company-Id' => $company->id])->assertOk();
        $this->assertSame('NOT_ENTITLED', $this->item($disabled->json(), 'fbr.invoicing')['unavailable_reason']);
        CompanyEntitlement::query()->where('company_id', $company->id)->where('module_key', 'invoicing')->update(['is_enabled' => true]);
        app(EntitlementService::class)->forget($company->id);
        $enabled = $this->getJson('/api/v1/platform/navigation', ['X-Company-Id' => $company->id])->assertOk();
        $this->assertContains('fbr.invoicing', $enabled->json('visible_keys'));
    }

    public function test_ordinary_user_cannot_administer_presentation_or_choose_unknown_item(): void
    {
        [, $company] = $this->actingAsCompanyUser(['pakistan_fbr.view']);
        $this->subscribe($company, ['invoicing']);

        $this->putJson('/api/v1/platform/navigation/fbr.invoicing', ['is_visible' => false], ['X-Company-Id' => $company->id])->assertForbidden();
        $this->deleteJson('/api/v1/platform/navigation/fbr.invoicing', [], ['X-Company-Id' => $company->id])->assertForbidden();
        $this->assertDatabaseCount('company_navigation_preferences', 0);
        $this->getJson('/api/v1/platform/navigation', ['X-Company-Id' => $company->id])->assertOk();
        $this->putJson('/api/v1/platform/navigation/unknown', ['is_visible' => false], ['X-Company-Id' => $company->id])->assertForbidden();

        [, $administratorCompany] = $this->actingAsCompanyUser(['platform.settings.manage']);
        $this->putJson('/api/v1/platform/navigation/unknown', ['is_visible' => true], ['X-Company-Id' => $administratorCompany->id])->assertNotFound();
        $this->putJson('/api/v1/platform/navigation/FBR.invoicing', ['is_visible' => true], ['X-Company-Id' => $administratorCompany->id])->assertNotFound();
    }

    public function test_write_requires_valid_boolean_and_ignores_untrusted_attributes(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['platform.settings.manage', 'pakistan_fbr.view']);
        $this->subscribe($company, ['invoicing']);
        $other = Company::factory()->create();

        $this->putJson('/api/v1/platform/navigation/fbr.invoicing', ['is_visible' => 'maybe'], ['X-Company-Id' => $company->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_visible']);
        $this->putJson('/api/v1/platform/navigation/fbr.invoicing', [
            'is_visible' => false, 'company_id' => $other->id, 'updated_by' => 999999, 'item_key' => 'accounting.invoices',
        ], ['X-Company-Id' => $company->id])->assertOk();

        $this->assertDatabaseHas('company_navigation_preferences', [
            'company_id' => $company->id, 'item_key' => 'fbr.invoicing', 'updated_by' => $user->id,
        ]);
        $this->assertDatabaseMissing('company_navigation_preferences', ['company_id' => $other->id]);
        $this->assertDatabaseCount('company_navigation_preferences', 1);
    }

    public function test_another_company_preference_cannot_be_mutated_by_reusing_its_id(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.settings.manage', 'pakistan_fbr.view']);
        $this->subscribe($company, ['invoicing']);
        $other = Company::factory()->create();
        $foreign = CompanyNavigationPreference::factory()->for($other)->create();

        $this->putJson('/api/v1/platform/navigation/fbr.invoicing', [
            'is_visible' => true, 'id' => $foreign->id,
        ], ['X-Company-Id' => $company->id])->assertOk();

        $this->assertFalse($foreign->fresh()->is_visible);
        $this->assertDatabaseHas('company_navigation_preferences', ['company_id' => $company->id, 'item_key' => 'fbr.invoicing', 'is_visible' => true]);
        $this->assertDatabaseCount('company_navigation_preferences', 2);
    }

    public function test_unauthenticated_navigation_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/platform/navigation')->assertUnauthorized();
        $this->putJson('/api/v1/platform/navigation/fbr.invoicing', ['is_visible' => false])->assertUnauthorized();
    }

    public function test_company_switch_recalculates_permission_entitlement_and_visibility(): void
    {
        [$user, $companyA] = $this->actingAsCompanyUser(['accounting.view', 'pakistan_fbr.view']);
        $this->subscribe($companyA, ['invoicing']);
        $companyB = Company::factory()->create();
        $this->subscribe($companyB, ['crm']);
        $roleB = Role::factory()->for($companyB)->create();
        $roleB->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'pakistan_fbr.view']));
        CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'role_id' => $roleB->id, 'is_active' => true]);
        $companyC = Company::factory()->create();
        $this->subscribe($companyC, ['invoicing']);
        $roleC = Role::factory()->for($companyC)->create();
        $roleC->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'pakistan_fbr.view']));
        CompanyUser::query()->create(['company_id' => $companyC->id, 'user_id' => $user->id, 'role_id' => $roleC->id, 'is_active' => true]);
        CompanyNavigationPreference::factory()->for($companyC)->create(['item_key' => 'fbr.invoicing', 'updated_by' => $user->id]);

        $this->assertContains('fbr.invoicing', $this->itemKeysForCompany($companyA->id));
        $this->assertNotContains('fbr.invoicing', $this->itemKeysForCompany($companyB->id));
        $this->assertNotContains('fbr.invoicing', $this->itemKeysForCompany($companyC->id));
        $this->postJson('/api/v1/auth/switch-company', ['company_id' => $companyB->id])->assertOk()
            ->assertJsonPath('company.effective_navigation.visible_keys', $this->itemKeysForCompany($companyB->id));
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('companies.0.modules.0', 'invoicing');
    }

    public function test_cross_company_headers_and_platform_admin_flag_do_not_bypass_membership_or_permissions(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.view']);
        $this->subscribe($company, ['invoicing']);
        $other = Company::factory()->create();
        $user->update(['is_platform_admin' => true]);

        $this->putJson('/api/v1/platform/navigation/fbr.invoicing', ['is_visible' => false], ['X-Company-Id' => $company->id])->assertForbidden();
        $this->getJson('/api/v1/platform/navigation', ['X-Company-Id' => $other->id])->assertForbidden();
        $this->putJson('/api/v1/platform/navigation/fbr.invoicing', ['is_visible' => false], ['X-Company-Id' => $other->id])->assertForbidden();
        $this->assertDatabaseCount('company_navigation_preferences', 0);
    }

    public function test_auth_me_keeps_commercial_modules_separate_from_effective_items(): void
    {
        [, $company] = $this->actingAsCompanyUser(['accounting.view']);
        $this->subscribe($company, ['invoicing']);

        $response = $this->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame(['invoicing'], $response->json('companies.0.modules'));
        $this->assertContains('accounting.invoices', $response->json('companies.0.effective_navigation.visible_keys'));
        $this->assertNotContains('fbr.invoicing', $response->json('companies.0.effective_navigation.visible_keys'));
    }

    /** @param array<string, mixed> $response @return array<string, mixed> */
    private function item(array $response, string $key): array
    {
        return collect($response['items'])->firstWhere('key', $key);
    }

    /** @return array<int, string> */
    private function itemKeysForCompany(string $companyId): array
    {
        return $this->getJson('/api/v1/platform/navigation', ['X-Company-Id' => $companyId])->assertOk()->json('visible_keys');
    }

    /** @param array<int, string> $modules */
    private function subscribe(Company $company, array $modules): Subscription
    {
        foreach ($modules as $module) {
            PlatformModule::query()->firstOrCreate(['key' => $module], ['name' => ucfirst($module)]);
        }
        $plan = Plan::factory()->create();
        $plan->modules()->attach($modules, ['is_enabled' => true]);

        return Subscription::factory()->for($company)->for($plan)->create(['status' => 'ACTIVE']);
    }

    private function assertNoFinancialEffects(): void
    {
        foreach (['invoices', 'pakistan_fbr_invoices', 'journals', 'journal_lines', 'customer_payments', 'inventory_movements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
