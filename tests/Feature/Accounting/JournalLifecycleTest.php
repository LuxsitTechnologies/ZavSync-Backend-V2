<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Journal;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JournalLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_rejects_account_from_another_company_with_422(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.post']);
        $otherCompany = Company::factory()->create();
        AccountingPeriod::factory()->for($company)->create();
        $cash = Account::factory()->for($company)->create(['created_by' => $user->id]);
        $otherRevenue = Account::factory()->for($otherCompany)->revenue()->create(['created_by' => $user->id]);

        $this->postJson('/api/v1/accounting/journals/post', $this->journalPayload($cash, $otherRevenue), $this->headers($company->id, 'cross-company'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.1.account_id');

        $this->assertDatabaseCount('journals', 0);
    }

    public function test_forbids_user_without_post_permission(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.view']);
        AccountingPeriod::factory()->for($company)->create();
        $cash = Account::factory()->for($company)->create(['created_by' => $user->id]);
        $revenue = Account::factory()->for($company)->revenue()->create(['created_by' => $user->id]);

        $this->postJson('/api/v1/accounting/journals/post', $this->journalPayload($cash, $revenue), $this->headers($company->id, 'unauthorized'))->assertForbidden();

        $this->assertDatabaseCount('journals', 0);
    }

    public function test_posting_draft_keeps_identity_and_posted_journal_cannot_be_edited(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.post']);
        AccountingPeriod::factory()->for($company)->create();
        $cash = Account::factory()->for($company)->create(['created_by' => $user->id]);
        $revenue = Account::factory()->for($company)->revenue()->create(['created_by' => $user->id]);
        $payload = $this->journalPayload($cash, $revenue);
        $draftId = $this->postJson('/api/v1/accounting/journals', [...$payload, 'status' => 'draft'], ['X-Company-Id' => $company->id])->assertCreated()->json('id');
        $this->patchJson('/api/v1/accounting/journals/'.$draftId, [...$payload, 'description' => 'Updated draft'], ['X-Company-Id' => $company->id])->assertOk()->assertJsonPath('description', 'Updated draft');

        $this->postJson('/api/v1/accounting/journals/'.$draftId.'/post', $payload, ['X-Company-Id' => $company->id])->assertOk()->assertJsonPath('id', $draftId)->assertJsonPath('status', 'posted');
        $this->patchJson('/api/v1/accounting/journals/'.$draftId, $payload, ['X-Company-Id' => $company->id])->assertUnprocessable();

        $this->assertDatabaseCount('journals', 1);
        $this->assertTrue(AuditLog::query()->where('entity_id', $draftId)->where('action', 'update_draft')->exists());
    }

    public function test_reversal_is_balanced_opposite_audited_and_cannot_repeat(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.post']);
        AccountingPeriod::factory()->for($company)->create();
        $cash = Account::factory()->for($company)->create(['created_by' => $user->id]);
        $revenue = Account::factory()->for($company)->revenue()->create(['created_by' => $user->id]);
        $journalId = $this->postJson('/api/v1/accounting/journals/post', $this->journalPayload($cash, $revenue), $this->headers($company->id, 'original'))->assertCreated()->json('id');

        $response = $this->postJson('/api/v1/accounting/journals/'.$journalId.'/reverse', ['posting_date' => '2026-09-23', 'reason' => 'Correction'], $this->headers($company->id, 'reverse-original'))->assertCreated();
        $response->assertJsonPath('reverses_journal_id', $journalId)->assertJsonPath('total_debit', 50000)->assertJsonPath('total_credit', 50000);
        $this->assertSame(50000, $response->json('lines.0.credit'));
        $this->postJson('/api/v1/accounting/journals/'.$journalId.'/reverse', ['posting_date' => '2026-09-23', 'reason' => 'Again'], $this->headers($company->id, 'reverse-again'))->assertUnprocessable();
        $this->assertTrue(AuditLog::query()->where('entity_id', $journalId)->where('action', 'reverse')->exists());
    }

    public function test_direct_post_is_idempotent_and_has_single_financial_effect(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.post']);
        AccountingPeriod::factory()->for($company)->create();
        $cash = Account::factory()->for($company)->create(['created_by' => $user->id]);
        $revenue = Account::factory()->for($company)->revenue()->create(['created_by' => $user->id]);
        $payload = $this->journalPayload($cash, $revenue);
        $headers = $this->headers($company->id, 'browser-double-click');

        $firstId = $this->postJson('/api/v1/accounting/journals/post', $payload, $headers)->assertCreated()->json('id');
        $secondId = $this->postJson('/api/v1/accounting/journals/post', $payload, $headers)->assertOk()->json('id');
        $changedPayload = $payload;
        $changedPayload['lines'][0]['debit'] = 60000;
        $changedPayload['lines'][1]['credit'] = 60000;
        $this->postJson('/api/v1/accounting/journals/post', $changedPayload, $headers)->assertConflict();

        $this->assertSame($firstId, $secondId);
        $this->assertDatabaseCount('journals', 1);
        $this->assertDatabaseCount('journal_lines', 2);
        $this->assertSame(1, AuditLog::query()->where('action', 'post')->count());
    }

    public function test_numbering_is_sequential_and_company_scoped(): void
    {
        [$userA, $companyA] = $this->actingAsCompanyUser(['accounting.post']);
        AccountingPeriod::factory()->for($companyA)->create();
        $cashA = Account::factory()->for($companyA)->create(['created_by' => $userA->id]);
        $revenueA = Account::factory()->for($companyA)->revenue()->create(['created_by' => $userA->id]);
        $first = $this->postJson('/api/v1/accounting/journals/post', $this->journalPayload($cashA, $revenueA), $this->headers($companyA->id, 'a-1'))->json('number');
        $second = $this->postJson('/api/v1/accounting/journals/post', $this->journalPayload($cashA, $revenueA), $this->headers($companyA->id, 'a-2'))->json('number');

        [$userB, $companyB] = $this->newCompanyMember(['accounting.post']);
        Sanctum::actingAs($userB);
        AccountingPeriod::factory()->for($companyB)->create();
        $cashB = Account::factory()->for($companyB)->create(['created_by' => $userB->id]);
        $revenueB = Account::factory()->for($companyB)->revenue()->create(['created_by' => $userB->id]);
        $companyBFirst = $this->postJson('/api/v1/accounting/journals/post', $this->journalPayload($cashB, $revenueB), $this->headers($companyB->id, 'b-1'))->json('number');

        $this->assertSame('JV-2026-0001', $first);
        $this->assertSame('JV-2026-0002', $second);
        $this->assertSame('JV-2026-0001', $companyBFirst);
    }

    public function test_returns_404_for_journal_owned_by_another_company(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.view']);
        $otherCompany = Company::factory()->create();
        $journal = Journal::factory()->for($otherCompany)->create(['created_by' => $user->id]);

        $this->getJson('/api/v1/accounting/journals/'.$journal->id, ['X-Company-Id' => $company->id])->assertNotFound();
    }

    public function test_posted_journal_has_no_destructive_delete_endpoint(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.post']);
        $journal = Journal::factory()->for($company)->create(['status' => 'posted', 'created_by' => $user->id, 'posted_by' => $user->id, 'posted_at' => now()]);

        $this->deleteJson('/api/v1/accounting/journals/'.$journal->id, [], ['X-Company-Id' => $company->id])->assertMethodNotAllowed();

        $this->assertModelExists($journal);
    }

    public function test_rejects_posting_outside_defined_period_with_422_and_rolls_back(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.post']);
        $cash = Account::factory()->for($company)->create(['created_by' => $user->id]);
        $revenue = Account::factory()->for($company)->revenue()->create(['created_by' => $user->id]);

        $this->postJson('/api/v1/accounting/journals/post', $this->journalPayload($cash, $revenue), $this->headers($company->id, 'missing-period'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('posting_date');

        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('journal_lines', 0);
    }

    public function test_rejects_inactive_account_and_meaningless_zero_line_with_422(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.post']);
        AccountingPeriod::factory()->for($company)->create();
        $cash = Account::factory()->for($company)->create(['is_active' => false, 'created_by' => $user->id]);
        $revenue = Account::factory()->for($company)->revenue()->create(['created_by' => $user->id]);

        $this->postJson('/api/v1/accounting/journals/post', $this->journalPayload($cash, $revenue), $this->headers($company->id, 'inactive-account'))->assertUnprocessable();
        $payload = $this->journalPayload($cash, $revenue);
        $payload['lines'][0] = ['account_id' => $cash->id, 'debit' => 0, 'credit' => 0];
        $this->postJson('/api/v1/accounting/journals/post', $payload, $this->headers($company->id, 'zero-line'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0');

        $this->assertDatabaseCount('journals', 0);
    }

    /** @return array<string, mixed> */
    private function journalPayload(Account $debit, Account $credit): array
    {
        return ['posting_date' => '2026-09-22', 'reference' => 'TEST-1', 'description' => 'Test journal', 'lines' => [['account_id' => $debit->id, 'description' => 'Debit', 'debit' => 50000, 'credit' => 0], ['account_id' => $credit->id, 'description' => 'Credit', 'debit' => 0, 'credit' => 50000]]];
    }

    /** @return array<string, string> */
    private function headers(string $companyId, string $idempotencyKey): array
    {
        return ['X-Company-Id' => $companyId, 'Idempotency-Key' => $idempotencyKey];
    }

    /** @param array<int, string> $permissions @return array{User, Company} */
    private function newCompanyMember(array $permissions): array
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Accountant']);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission);
        }
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'role_id' => $role->id]);

        return [$user, $company];
    }
}
