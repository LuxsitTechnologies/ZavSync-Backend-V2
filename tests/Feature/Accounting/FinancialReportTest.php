<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Models\Journal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class FinancialReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_trial_balance_uses_posted_activity_and_balances(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.view']);
        [$cash, $equity, $revenue, $expense] = $this->accounts($company, $user->id);
        $this->postedJournal($company, $user->id, $cash, $equity, 100000, 1);
        $this->postedJournal($company, $user->id, $cash, $revenue, 50000, 2);
        $this->postedJournal($company, $user->id, $expense, $cash, 20000, 3);
        $draft = Journal::factory()->for($company)->create(['sequence' => 4, 'number' => 'JV-2026-0004', 'status' => 'draft', 'created_by' => $user->id]);
        $draft->lines()->createMany([['account_id' => $cash->id, 'debit' => 999000, 'credit' => 0], ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 999000]]);

        $this->getJson('/api/v1/accounting/reports/trial-balance?to=2026-09-30', ['X-Company-Id' => $company->id])
            ->assertOk()
            ->assertJsonPath('debit', 150000)
            ->assertJsonPath('credit', 150000)
            ->assertJsonPath('movement_debit', 170000)
            ->assertJsonPath('movement_credit', 170000)
            ->assertJsonPath('balanced', true);
    }

    public function test_profit_and_loss_derives_only_from_posted_journals(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.view']);
        [$cash, , $revenue, $expense] = $this->accounts($company, $user->id);
        $this->postedJournal($company, $user->id, $cash, $revenue, 50000, 1);
        $this->postedJournal($company, $user->id, $expense, $cash, 20000, 2);

        $this->getJson('/api/v1/accounting/reports/profit-and-loss?from=2026-09-01&to=2026-09-30', ['X-Company-Id' => $company->id])
            ->assertOk()
            ->assertJsonPath('revenue', 50000)
            ->assertJsonPath('operating_expenses', 20000)
            ->assertJsonPath('net_profit', 30000);
    }

    public function test_balance_sheet_includes_current_earnings_without_fake_journal(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.view']);
        [$cash, $equity, $revenue, $expense] = $this->accounts($company, $user->id);
        $this->postedJournal($company, $user->id, $cash, $equity, 100000, 1);
        $this->postedJournal($company, $user->id, $cash, $revenue, 50000, 2);
        $this->postedJournal($company, $user->id, $expense, $cash, 20000, 3);

        $this->getJson('/api/v1/accounting/reports/balance-sheet?as_of=2026-09-30', ['X-Company-Id' => $company->id])
            ->assertOk()
            ->assertJsonPath('assets', 130000)
            ->assertJsonPath('equity', 100000)
            ->assertJsonPath('current_earnings', 30000)
            ->assertJsonPath('balanced', true)
            ->assertJsonPath('difference', 0);

        $this->assertDatabaseCount('journals', 3);
    }

    public function test_company_a_reports_exclude_company_b(): void
    {
        [$user, $companyA] = $this->actingAsCompanyUser(['accounting.view']);
        [$cashA, , $revenueA] = $this->accounts($companyA, $user->id);
        $this->postedJournal($companyA, $user->id, $cashA, $revenueA, 50000, 1);
        $companyB = Company::factory()->create();
        [$cashB, , $revenueB] = $this->accounts($companyB, $user->id);
        $this->postedJournal($companyB, $user->id, $cashB, $revenueB, 90000, 1);

        $this->getJson('/api/v1/accounting/reports/profit-and-loss?to=2026-09-30', ['X-Company-Id' => $companyA->id])
            ->assertOk()
            ->assertJsonPath('revenue', 50000);
    }

    public function test_trial_balance_reports_unbalanced_opening_balances(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.view']);
        Account::factory()->for($company)->create(['code' => '1000', 'opening_balance' => 25000, 'created_by' => $user->id]);

        $this->getJson('/api/v1/accounting/reports/trial-balance?to=2026-09-30', ['X-Company-Id' => $company->id])
            ->assertOk()
            ->assertJsonPath('debit', 25000)
            ->assertJsonPath('credit', 0)
            ->assertJsonPath('balanced', false);
    }

    /** @return array{Account, Account, Account, Account} */
    private function accounts(Company $company, int $userId): array
    {
        return [
            Account::factory()->for($company)->create(['code' => '1000', 'created_by' => $userId]),
            Account::factory()->for($company)->equity()->create(['code' => '3000', 'created_by' => $userId]),
            Account::factory()->for($company)->revenue()->create(['code' => '4000', 'created_by' => $userId]),
            Account::factory()->for($company)->expense()->create(['code' => '6000', 'created_by' => $userId]),
        ];
    }

    private function postedJournal(Company $company, int $userId, Account $debit, Account $credit, int $amount, int $sequence): void
    {
        $journal = Journal::factory()->for($company)->create(['sequence' => $sequence, 'number' => sprintf('JV-2026-%04d', $sequence), 'posting_date' => '2026-09-22', 'status' => 'posted', 'created_by' => $userId, 'posted_by' => $userId, 'posted_at' => now()]);
        $journal->lines()->createMany([['account_id' => $debit->id, 'debit' => $amount, 'credit' => 0], ['account_id' => $credit->id, 'debit' => 0, 'credit' => $amount]]);
    }
}
