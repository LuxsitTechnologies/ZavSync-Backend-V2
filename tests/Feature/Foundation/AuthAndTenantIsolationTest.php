<?php

namespace Tests\Feature\Foundation;

use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthAndTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_employee_api_request_without_json_accept_returns_safe_json_401(): void
    {
        $response = $this->get('/api/v1/employee/me')->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->assertSame(['message' => 'Unauthenticated.'], $response->json());
        $this->assertFalse($response->headers->has('Location'));
        $this->assertStringNotContainsString('Route [login]', $response->getContent());
        $this->assertStringNotContainsString('bootstrap/app.php', $response->getContent());
        $this->assertStringNotContainsString('vendor/laravel', $response->getContent());
    }

    public function test_unauthenticated_protected_api_routes_reject_before_company_resolution(): void
    {
        foreach (['/api/v1/auth/me', '/api/v1/accounting/accounts', '/api/v1/platform/notifications'] as $path) {
            $this->get($path)->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
            $this->get($path, ['X-Company-Id' => 'not-a-company'])->assertUnauthorized()
                ->assertJsonPath('message', 'Unauthenticated.');
        }
    }

    public function test_authenticated_sanctum_api_and_company_resolution_remain_unchanged(): void
    {
        [, $company] = $this->actingAsCompanyUser(['employee.self.view']);

        $this->get('/api/v1/employee/me', ['X-Company-Id' => $company->id])->assertOk()
            ->assertJsonPath('linked', false);
        $this->get('/api/v1/employee/me')->assertUnprocessable()
            ->assertJsonPath('error_code', 'COMPANY_CONTEXT_REQUIRED');
        $this->get('/')->assertOk();
    }

    public function test_user_cannot_read_another_companies_accounts(): void
    {
        [$user, $companyA] = $this->memberWithPermission('accounting.view');
        $companyB = Company::factory()->create();
        Account::factory()->for($companyB)->create(['created_by' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/accounting/accounts', ['X-Company-Id' => $companyB->id])->assertForbidden();
        $this->getJson('/api/v1/accounting/accounts', ['X-Company-Id' => $companyA->id])->assertOk()->assertJsonCount(0);
    }

    /** @return array{User, Company} */
    private function memberWithPermission(string $permissionName): array
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $permission = Permission::query()->create(['name' => $permissionName]);
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Tester']);
        $role->permissions()->attach($permission);
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role_id' => $role->id, 'is_active' => true]);

        return [$user, $company];
    }
}
