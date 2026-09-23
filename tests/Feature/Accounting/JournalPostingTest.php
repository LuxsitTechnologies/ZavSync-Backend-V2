<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JournalPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_balanced_journal_posts_atomically(): void
    {
        [$user, $company] = $this->accountingMember();
        $cash = Account::factory()->for($company)->create(['code' => '1000', 'created_by' => $user->id]);
        $revenue = Account::factory()->for($company)->create(['code' => '4000', 'type' => 'revenue', 'created_by' => $user->id]);
        AccountingPeriod::factory()->for($company)->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/accounting/journals', ['posting_date' => '2026-09-22', 'reference' => 'SALE-1', 'description' => 'Cash sale', 'lines' => [['account_id' => $cash->id, 'debit' => 500000, 'credit' => 0], ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 500000]]], ['X-Company-Id' => $company->id, 'Idempotency-Key' => 'cash-sale-1'])->assertCreated()->assertJsonPath('total_debit', 500000)->assertJsonPath('total_credit', 500000);
        $this->assertDatabaseCount('journals', 1);
        $this->assertDatabaseCount('journal_lines', 2);
    }

    public function test_unbalanced_and_locked_period_journals_are_rejected(): void
    {
        [$user, $company] = $this->accountingMember();
        $cash = Account::factory()->for($company)->create(['created_by' => $user->id]);
        $revenue = Account::factory()->for($company)->create(['type' => 'revenue', 'created_by' => $user->id]);
        Sanctum::actingAs($user);
        $payload = ['posting_date' => '2026-09-22', 'description' => 'Invalid', 'lines' => [['account_id' => $cash->id, 'debit' => 500, 'credit' => 0], ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 400]]];
        $this->postJson('/api/v1/accounting/journals', $payload, ['X-Company-Id' => $company->id])->assertUnprocessable();
        AccountingPeriod::factory()->for($company)->create(['status' => 'closed']);
        $payload['lines'][1]['credit'] = 500;
        $this->postJson('/api/v1/accounting/journals', $payload, ['X-Company-Id' => $company->id])->assertUnprocessable();
        $this->assertDatabaseCount('journals', 0);
    }

    /** @return array{User, Company} */
    private function accountingMember(): array
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $permission = Permission::query()->create(['name' => 'accounting.post']);
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Accountant']);
        $role->permissions()->attach($permission);
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role_id' => $role->id]);

        return [$user, $company];
    }
}
