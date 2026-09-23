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
