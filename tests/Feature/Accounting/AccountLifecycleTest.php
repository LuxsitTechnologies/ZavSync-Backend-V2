<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Journal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_rejects_duplicate_code_in_same_company_with_422(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.create']);
        Account::factory()->for($company)->create(['code' => '1000', 'created_by' => $user->id]);

        $this->postJson('/api/v1/accounting/accounts', $this->accountPayload('1000'), ['X-Company-Id' => $company->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_allows_same_code_in_different_companies(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.create']);
        $otherCompany = Company::factory()->create();
        Account::factory()->for($otherCompany)->create(['code' => '1000', 'created_by' => $user->id]);

        $this->postJson('/api/v1/accounting/accounts', $this->accountPayload('1000'), ['X-Company-Id' => $company->id])
            ->assertCreated()
            ->assertJsonPath('normal_balance', 'debit');

        $this->assertDatabaseCount('accounts', 2);
    }

    public function test_rejects_circular_hierarchy_with_422(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.edit']);
        $parent = Account::factory()->for($company)->create(['code' => '1000', 'created_by' => $user->id]);
        $child = Account::factory()->for($company)->create(['code' => '1010', 'parent_id' => $parent->id, 'created_by' => $user->id]);

        $this->patchJson('/api/v1/accounting/accounts/'.$parent->id, [...$this->accountPayload('1000'), 'parent_id' => $child->id], ['X-Company-Id' => $company->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_used_account_has_no_destructive_delete_endpoint(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.edit']);
        $account = Account::factory()->for($company)->create(['created_by' => $user->id]);
        $journal = Journal::factory()->for($company)->create(['created_by' => $user->id, 'status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now()]);
        $journal->lines()->create(['account_id' => $account->id, 'debit' => 100, 'credit' => 0]);

        $this->deleteJson('/api/v1/accounting/accounts/'.$account->id, [], ['X-Company-Id' => $company->id])
            ->assertMethodNotAllowed();

        $this->assertModelExists($account);
    }

    public function test_deactivation_is_audited(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.edit']);
        $account = Account::factory()->for($company)->create(['created_by' => $user->id]);

        $this->patchJson('/api/v1/accounting/accounts/'.$account->id.'/status', ['is_active' => false], ['X-Company-Id' => $company->id])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertFalse($account->fresh()->is_active);
        $this->assertTrue(AuditLog::query()->where('company_id', $company->id)->where('entity_id', $account->id)->where('action', 'archive')->exists());
    }

    public function test_account_update_is_audited(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.edit']);
        $account = Account::factory()->for($company)->create(['code' => '1000', 'created_by' => $user->id]);

        $this->patchJson('/api/v1/accounting/accounts/'.$account->id, [...$this->accountPayload('1000'), 'name' => 'Main Cash'], ['X-Company-Id' => $company->id])
            ->assertOk()
            ->assertJsonPath('name', 'Main Cash');

        $this->assertTrue(AuditLog::query()->where('entity_id', $account->id)->where('action', 'update')->exists());
    }

    /** @return array<string, mixed> */
    private function accountPayload(string $code): array
    {
        return ['code' => $code, 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset', 'parent_id' => null, 'is_active' => true, 'description' => null, 'opening_balance' => 0, 'opening_balance_date' => null];
    }
}
