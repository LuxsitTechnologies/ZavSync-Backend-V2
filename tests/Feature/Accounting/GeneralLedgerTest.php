<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\Journal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GeneralLedgerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_draft_is_excluded_and_posted_journal_affects_ledger(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.view', 'accounting.post']);
        AccountingPeriod::factory()->for($company)->create();
        $cash = Account::factory()->for($company)->create(['opening_balance' => 10000, 'created_by' => $user->id]);
        $revenue = Account::factory()->for($company)->revenue()->create(['created_by' => $user->id]);
        $payload = $this->payload($cash, $revenue);
        $this->postJson('/api/v1/accounting/journals', [...$payload, 'status' => 'draft'], ['X-Company-Id' => $company->id])->assertCreated();

        $this->getJson('/api/v1/accounting/ledger?account_id='.$cash->id, ['X-Company-Id' => $company->id])->assertOk()->assertJsonCount(0, 'entries')->assertJsonPath('closing_balance', 10000);
        $this->postJson('/api/v1/accounting/journals/post', $payload, ['X-Company-Id' => $company->id, 'Idempotency-Key' => 'ledger-post'])->assertCreated();
        $this->getJson('/api/v1/accounting/ledger?account_id='.$cash->id, ['X-Company-Id' => $company->id])
            ->assertOk()
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('opening_balance', 10000)
            ->assertJsonPath('closing_balance', 60000)
            ->assertJsonPath('entries.0.running_balance', 60000);
    }

    public function test_date_range_returns_authoritative_opening_and_paginated_running_balance(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.view']);
        $cash = Account::factory()->for($company)->create(['opening_balance' => 1000, 'created_by' => $user->id]);
        $revenue = Account::factory()->for($company)->revenue()->create(['created_by' => $user->id]);
        $this->postedJournal($company, $user->id, $cash, $revenue, '2026-08-31', 2000, 1);
        $this->postedJournal($company, $user->id, $cash, $revenue, '2026-09-10', 3000, 2);
        $this->postedJournal($company, $user->id, $cash, $revenue, '2026-09-20', 4000, 3);

        $this->getJson('/api/v1/accounting/ledger?account_id='.$cash->id.'&from=2026-09-01&to=2026-09-30&per_page=1&page=2', ['X-Company-Id' => $company->id])
            ->assertOk()
            ->assertJsonPath('opening_balance', 3000)
            ->assertJsonPath('total_debit', 7000)
            ->assertJsonPath('closing_balance', 10000)
            ->assertJsonPath('entries.0.running_balance', 10000)
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_company_report_excludes_other_company_entries(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.view']);
        $cash = Account::factory()->for($company)->create(['created_by' => $user->id]);
        $revenue = Account::factory()->for($company)->revenue()->create(['created_by' => $user->id]);
        $this->postedJournal($company, $user->id, $cash, $revenue, '2026-09-10', 5000, 1);
        $other = Company::factory()->create();
        $otherCash = Account::factory()->for($other)->create(['created_by' => $user->id]);
        $otherRevenue = Account::factory()->for($other)->revenue()->create(['created_by' => $user->id]);
        $this->postedJournal($other, $user->id, $otherCash, $otherRevenue, '2026-09-10', 9000, 1);

        $this->getJson('/api/v1/accounting/ledger', ['X-Company-Id' => $company->id])
            ->assertOk()
            ->assertJsonPath('total_debit', 5000)
            ->assertJsonCount(2, 'entries');
    }

    /** @return array<string, mixed> */
    private function payload(Account $cash, Account $revenue): array
    {
        return ['posting_date' => '2026-09-22', 'description' => 'Sale', 'lines' => [['account_id' => $cash->id, 'debit' => 50000, 'credit' => 0], ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 50000]]];
    }

    private function postedJournal(Company $company, int $userId, Account $debit, Account $credit, string $date, int $amount, int $sequence): void
    {
        $journal = Journal::factory()->for($company)->create(['sequence' => $sequence, 'number' => sprintf('JV-2026-%04d', $sequence), 'posting_date' => $date, 'status' => 'posted', 'created_by' => $userId, 'posted_by' => $userId, 'posted_at' => now()]);
        $journal->lines()->createMany([['account_id' => $debit->id, 'debit' => $amount, 'credit' => 0], ['account_id' => $credit->id, 'debit' => 0, 'credit' => $amount]]);
    }
}
