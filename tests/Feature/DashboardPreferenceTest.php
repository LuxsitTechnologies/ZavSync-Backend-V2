<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\PlatformModule;
use App\Models\Role;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_scoped_layout_is_versioned_audited_and_does_not_grant_data_access(): void
    {
        [$user, $companyA] = $this->actingAsCompanyUser(['platform.settings.manage', 'accounting.view']);
        $this->subscribe($companyA, ['invoicing']);
        $companyB = Company::factory()->create();
        $roleB = Role::factory()->for($companyB)->create();
        $roleB->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'platform.settings.view']));
        CompanyUser::query()->create(['company_id' => $companyB->id, 'user_id' => $user->id, 'role_id' => $roleB->id, 'is_active' => true]);
        $this->subscribe($companyB, ['invoicing', 'receivables']);
        $headersA = ['X-Company-Id' => $companyA->id];
        $headersB = ['X-Company-Id' => $companyB->id];

        $initial = $this->getJson('/api/v1/platform/dashboard-layout', $headersA)->assertOk();
        $this->assertCount(7, $initial->json('widgets'));
        $this->assertSame(1, $initial->json('layout_version'));
        $this->assertFalse($initial->json('widgets.1.effective_visible'));
        $widgets = $initial->json('widgets');
        $widgets[0]['visible'] = false;
        $inputWidgets = array_map(fn (array $item): array => ['key' => $item['key'], 'visible' => $item['visible']], $widgets);
        $response = $this->putJson('/api/v1/platform/dashboard-layout', [
            'layout_version' => 1, 'columns' => 2, 'widgets' => array_reverse($inputWidgets),
        ], $headersA)->assertOk()->assertJsonPath('layout_version', 2)->assertJsonPath('columns', 2);
        $this->assertSame('recent_invoices', $response->json('widgets.0.key'));
        $this->assertFalse($response->json('widgets.6.effective_visible'));
        $this->getJson('/api/v1/platform/dashboard-layout', $headersB)->assertOk()
            ->assertJsonPath('layout_version', 1)->assertJsonPath('columns', 4);
        $this->putJson('/api/v1/platform/dashboard-layout', ['layout_version' => 1, 'columns' => 1, 'widgets' => $inputWidgets], $headersA)
            ->assertConflict()->assertJsonPath('error_code', 'DASHBOARD_LAYOUT_VERSION_STALE');
        $this->deleteJson('/api/v1/platform/dashboard-layout', ['layout_version' => 2], $headersA)
            ->assertOk()->assertJsonPath('layout_version', 3)->assertJsonPath('columns', 4);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $companyA->id, 'action' => 'dashboard_layout_updated']);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_layout_validation_and_authorization_reject_unknown_duplicate_and_cross_company_updates(): void
    {
        [, $company] = $this->actingAsCompanyUser(['platform.settings.view']);
        $headers = ['X-Company-Id' => $company->id];
        $widgets = array_map(fn (array $item): array => ['key' => $item['key'], 'visible' => true],
            $this->getJson('/api/v1/platform/dashboard-layout', $headers)->assertOk()->json('widgets'));
        $this->putJson('/api/v1/platform/dashboard-layout', ['layout_version' => 1, 'columns' => 2, 'widgets' => $widgets], $headers)->assertForbidden();

        [, $administratorCompany] = $this->actingAsCompanyUser(['platform.settings.manage']);
        $administratorHeaders = ['X-Company-Id' => $administratorCompany->id];
        $this->putJson('/api/v1/platform/dashboard-layout', ['layout_version' => 1, 'columns' => 2, 'widgets' => $widgets], $headers)->assertForbidden();
        $widgets[0]['key'] = 'unknown';
        $this->putJson('/api/v1/platform/dashboard-layout', ['layout_version' => 1, 'columns' => 2, 'widgets' => $widgets], $administratorHeaders)->assertUnprocessable();
        $widgets[0]['key'] = $widgets[1]['key'];
        $this->putJson('/api/v1/platform/dashboard-layout', ['layout_version' => 1, 'columns' => 2, 'widgets' => $widgets], $administratorHeaders)->assertUnprocessable();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_unauthenticated_layout_request_is_json_401(): void
    {
        $this->getJson('/api/v1/platform/dashboard-layout')->assertUnauthorized();
    }

    /** @param array<int, string> $modules */
    private function subscribe(Company $company, array $modules): void
    {
        foreach ($modules as $module) {
            PlatformModule::query()->firstOrCreate(['key' => $module], ['name' => ucfirst($module)]);
        }
        $plan = Plan::factory()->create();
        $plan->modules()->attach($modules, ['is_enabled' => true]);
        Subscription::factory()->for($company)->for($plan)->create(['status' => 'ACTIVE']);
    }
}
