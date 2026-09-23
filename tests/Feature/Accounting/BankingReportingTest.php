<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\SupplierBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankingReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_cash_and_direct_cash_movement_derive_from_posted_gl(): void
    {
        $context = $this->stage6BankingContext();
        $journal = Journal::factory()->for($context['company'])->create(['posting_date' => '2026-09-20', 'status' => 'posted', 'source' => 'banking', 'created_by' => $context['user']->id, 'posted_by' => $context['user']->id]);
        $journal->lines()->createMany([
            ['account_id' => $context['accounts']['bank']->id, 'debit' => 50000, 'credit' => 0],
            ['account_id' => $context['accounts']['interest_income']->id, 'debit' => 0, 'credit' => 50000],
        ]);
        $headers = $this->headers($context['company']->id);
        $this->getJson('/api/v1/banking/cash-position?as_of=2026-09-30', $headers)->assertOk()->assertJsonPath('total', 50000)->assertJsonFragment(['name' => 'Primary Bank', 'book_balance' => 50000]);
        $this->getJson('/api/v1/banking/cash-movement?from=2026-09-01&to=2026-09-30', $headers)->assertOk()->assertJsonPath('inflows', 50000)->assertJsonPath('net_movement', 50000);
    }

    public function test_forecast_uses_open_ar_and_ap_and_excludes_paid_items(): void
    {
        $context = $this->stage6BankingContext();
        Invoice::factory()->for($context['company'])->for($context['customer'])->create(['status' => 'unpaid', 'due_date' => '2026-09-25', 'balance_due' => 80000, 'total' => 80000, 'created_by' => $context['user']->id]);
        Invoice::factory()->for($context['company'])->for($context['customer'])->create(['status' => 'paid', 'due_date' => '2026-09-25', 'balance_due' => 0, 'total' => 20000, 'amount_paid' => 20000, 'created_by' => $context['user']->id]);
        SupplierBill::factory()->for($context['company'])->for($context['supplier'])->create(['status' => 'unpaid', 'due_date' => '2026-09-28', 'balance_due' => 30000, 'total' => 30000, 'gross_total' => 30000, 'created_by' => $context['user']->id]);
        SupplierBill::factory()->for($context['company'])->for($context['supplier'])->create(['status' => 'void', 'due_date' => '2026-09-28', 'balance_due' => 20000, 'total' => 20000, 'gross_total' => 20000, 'created_by' => $context['user']->id]);

        $this->getJson('/api/v1/banking/cash-forecast?as_of=2026-09-20&horizon=30', $this->headers($context['company']->id))->assertOk()->assertJsonPath('expected_inflows', 80000)->assertJsonPath('expected_outflows', 30000)->assertJsonPath('projected_cash', 50000);
        foreach ([7, 30, 60, 90] as $horizon) {
            $this->getJson("/api/v1/banking/cash-forecast?as_of=2026-09-20&horizon={$horizon}", $this->headers($context['company']->id))->assertOk()->assertJsonPath('horizon_days', $horizon);
        }
    }

    public function test_forecast_rejects_unsafe_cross_currency_consolidation(): void
    {
        $context = $this->stage6BankingContext();
        $usdGl = Account::factory()->for($context['company'])->create(['code' => '1030', 'currency' => 'USD', 'created_by' => $context['user']->id]);
        FinancialAccount::factory()->for($context['company'])->create(['currency' => 'USD', 'gl_account_id' => $usdGl->id, 'created_by' => $context['user']->id]);

        $this->getJson('/api/v1/banking/cash-forecast?as_of=2026-09-20&horizon=30', $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors('currency');
    }

    public function test_bank_gl_exposes_statement_book_unmatched_and_difference(): void
    {
        $context = $this->stage6BankingContext();
        BankTransaction::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $context['bank']->id, 'created_by' => $context['user']->id, 'direction' => 'credit', 'amount' => 10000, 'running_balance' => 10000, 'transaction_date' => '2026-09-20']);

        $this->getJson("/api/v1/banking/accounts/{$context['bank']->id}/bank-gl?as_of=2026-09-30", $this->headers($context['company']->id))->assertOk()->assertJsonPath('statement_balance', 10000)->assertJsonPath('book_balance', 0)->assertJsonPath('unmatched_credits', 10000)->assertJsonPath('difference', 10000)->assertJsonPath('status', 'unbalanced');
    }

    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId];
    }
}
