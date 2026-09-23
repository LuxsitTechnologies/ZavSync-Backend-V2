<?php

namespace Tests\Feature\Accounting;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_create_a_company_scoped_account(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $permissions = collect(['accounting.create', 'accounting.view'])->map(fn (string $name) => Permission::query()->create(['name' => $name]));
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Accountant']);
        $role->permissions()->attach($permissions);
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role_id' => $role->id]);
        Sanctum::actingAs($user);
        $payload = ['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'parent_id' => null, 'is_active' => true, 'description' => null, 'opening_balance' => 125000, 'opening_balance_date' => '2026-09-01'];

        $this->postJson('/api/v1/accounting/accounts', $payload, ['X-Company-Id' => $company->id])->assertCreated()->assertJsonPath('company_id', $company->id)->assertJsonPath('opening_balance', 125000);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'create', 'module' => 'accounting']);
    }
}
