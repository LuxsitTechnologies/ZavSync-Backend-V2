<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\BankTransaction;
use App\Models\FinancialAccount;
use App\Models\GatewaySettlement;
use App\Models\InternalTransfer;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\SupplierBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankingAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_fee_classification_posts_once_and_is_idempotent(): void
    {
        $context = $this->stage6BankingContext();
        $transaction = BankTransaction::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $context['bank']->id, 'created_by' => $context['user']->id, 'direction' => 'debit', 'amount' => 500, 'transaction_date' => '2026-09-20']);
        $payload = ['counterpart_account_id' => $context['accounts']['bank_charges']->id, 'posting_date' => '2026-09-20', 'description' => 'Monthly bank charge'];
        $headers = $this->headers($context['company']->id, 'bank-fee');
        $this->postJson("/api/v1/banking/transactions/{$transaction->id}/classify", $payload, $headers)->assertOk()->assertJsonPath('status', 'classified');
        $this->postJson("/api/v1/banking/transactions/{$transaction->id}/classify", $payload, $headers)->assertOk();

        $journalId = $transaction->fresh()->classification_journal_id;
        $this->assertDatabaseHas('journal_lines', ['journal_id' => $journalId, 'account_id' => $context['accounts']['bank_charges']->id, 'debit' => 500]);
        $this->assertDatabaseHas('journal_lines', ['journal_id' => $journalId, 'account_id' => $context['accounts']['bank']->id, 'credit' => 500]);
        $this->assertDatabaseCount('journals', 1);
    }

    public function test_locked_period_rolls_back_bank_classification(): void
    {
        $context = $this->stage6BankingContext();
        AccountingPeriod::query()->where('company_id', $context['company']->id)->update(['status' => 'closed']);
        $transaction = BankTransaction::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $context['bank']->id, 'created_by' => $context['user']->id, 'direction' => 'credit', 'amount' => 2500, 'transaction_date' => '2026-09-20']);

        $this->postJson("/api/v1/banking/transactions/{$transaction->id}/classify", ['counterpart_account_id' => $context['accounts']['interest_income']->id, 'posting_date' => '2026-09-20', 'description' => 'Interest'], $this->headers($context['company']->id, 'locked-interest'))->assertUnprocessable()->assertJsonValidationErrors('posting_date');
        $this->assertDatabaseCount('journals', 0);
        $this->assertSame('unmatched', $transaction->fresh()->status->value);
    }

    public function test_locked_period_rejects_cash_transfer_and_gateway_financial_effects(): void
    {
        $context = $this->stage6BankingContext();
        AccountingPeriod::query()->where('company_id', $context['company']->id)->update(['status' => 'closed']);
        $destinationGl = Account::factory()->for($context['company'])->create(['code' => '1030', 'created_by' => $context['user']->id]);
        $destination = FinancialAccount::factory()->for($context['company'])->create(['gl_account_id' => $destinationGl->id, 'created_by' => $context['user']->id]);

        $this->postJson('/api/v1/banking/cash-transactions', ['financial_account_id' => $context['cash']->id, 'direction' => 'credit', 'amount' => 1000, 'transaction_date' => '2026-09-20', 'description' => 'Locked cash', 'counterpart_account_id' => $context['accounts']['interest_income']->id], $this->headers($context['company']->id, 'locked-cash'))->assertUnprocessable()->assertJsonValidationErrors('posting_date');
        $this->postJson('/api/v1/banking/internal-transfers', ['source_financial_account_id' => $context['bank']->id, 'destination_financial_account_id' => $destination->id, 'transfer_date' => '2026-09-20', 'amount' => 1000], $this->headers($context['company']->id, 'locked-transfer'))->assertUnprocessable()->assertJsonValidationErrors('posting_date');
        $this->postJson('/api/v1/banking/settlements', ['provider' => 'Gateway', 'settlement_reference' => 'LOCKED-SET', 'settlement_date' => '2026-09-20', 'gross_amount' => 1000, 'fee_amount' => 100, 'adjustment_amount' => 0, 'net_amount' => 900, 'currency' => 'PKR', 'destination_financial_account_id' => $context['bank']->id, 'clearing_account_id' => $context['accounts']['gateway_clearing']->id, 'fee_account_id' => $context['accounts']['gateway_fees']->id, 'post' => true], $this->headers($context['company']->id, 'locked-settlement'))->assertUnprocessable()->assertJsonValidationErrors('posting_date');

        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('internal_transfers', 0);
        $this->assertDatabaseCount('gateway_settlements', 0);
    }

    public function test_manual_cash_receipt_posts_balanced_journal_and_retries_safely(): void
    {
        $context = $this->stage6BankingContext();
        $payload = ['financial_account_id' => $context['cash']->id, 'direction' => 'credit', 'amount' => 7500, 'transaction_date' => '2026-09-20', 'description' => 'Owner cash contribution', 'reference' => 'CASH-1', 'counterpart_account_id' => $context['accounts']['interest_income']->id];
        $headers = $this->headers($context['company']->id, 'cash-one');
        $first = $this->postJson('/api/v1/banking/cash-transactions', $payload, $headers)->assertOk();
        $second = $this->postJson('/api/v1/banking/cash-transactions', $payload, $headers)->assertOk();
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('bank_transactions', 1);
        $this->assertDatabaseCount('journals', 1);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $context['accounts']['cash']->id, 'debit' => 7500]);
    }

    public function test_internal_transfer_posts_one_journal_and_rejects_cross_currency(): void
    {
        $context = $this->stage6BankingContext();
        $destinationGl = Account::factory()->for($context['company'])->create(['code' => '1030', 'created_by' => $context['user']->id]);
        $destination = FinancialAccount::factory()->for($context['company'])->create(['gl_account_id' => $destinationGl->id, 'created_by' => $context['user']->id]);
        $payload = ['source_financial_account_id' => $context['bank']->id, 'destination_financial_account_id' => $destination->id, 'transfer_date' => '2026-09-20', 'amount' => 25000, 'reference' => 'MOVE-1'];
        $headers = $this->headers($context['company']->id, 'transfer-one');
        $first = $this->postJson('/api/v1/banking/internal-transfers', $payload, $headers)->assertOk();
        $second = $this->postJson('/api/v1/banking/internal-transfers', $payload, $headers)->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $transfer = InternalTransfer::query()->firstOrFail();
        $this->assertDatabaseHas('journal_lines', ['journal_id' => $transfer->journal_id, 'account_id' => $destinationGl->id, 'debit' => 25000]);
        $this->assertDatabaseHas('journal_lines', ['journal_id' => $transfer->journal_id, 'account_id' => $context['accounts']['bank']->id, 'credit' => 25000]);
        $this->assertDatabaseCount('journals', 1);
        $debitEvidence = BankTransaction::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $context['bank']->id, 'created_by' => $context['user']->id, 'direction' => 'debit', 'amount' => 25000, 'transaction_date' => '2026-09-20']);
        $creditEvidence = BankTransaction::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $destination->id, 'created_by' => $context['user']->id, 'direction' => 'credit', 'amount' => 25000, 'transaction_date' => '2026-09-20']);
        $matchPayload = ['matchable_type' => 'internal_transfer', 'matchable_id' => $transfer->id, 'amount' => 25000];
        $this->postJson("/api/v1/banking/transactions/{$debitEvidence->id}/match", $matchPayload, $this->headers($context['company']->id, 'transfer-source-match'))->assertOk();
        $this->postJson("/api/v1/banking/transactions/{$creditEvidence->id}/match", $matchPayload, $this->headers($context['company']->id, 'transfer-destination-match'))->assertOk();
        $this->assertDatabaseCount('bank_reconciliation_matches', 2);
        $this->assertDatabaseCount('journals', 1);

        $destination->update(['currency' => 'USD']);
        $this->postJson('/api/v1/banking/internal-transfers', $payload, $this->headers($context['company']->id, 'transfer-fx'))->assertUnprocessable()->assertJsonValidationErrors('currency');
    }

    public function test_gateway_settlement_validates_equation_and_posts_clearing_fee_and_net(): void
    {
        $context = $this->stage6BankingContext();
        $payload = ['provider' => 'JazzCash', 'settlement_reference' => 'SET-100', 'settlement_date' => '2026-09-20', 'gross_amount' => 100000, 'fee_amount' => 3000, 'adjustment_amount' => 0, 'net_amount' => 97000, 'currency' => 'PKR', 'destination_financial_account_id' => $context['bank']->id, 'clearing_account_id' => $context['accounts']['gateway_clearing']->id, 'fee_account_id' => $context['accounts']['gateway_fees']->id, 'post' => true];
        $response = $this->postJson('/api/v1/banking/settlements', $payload, $this->headers($context['company']->id, 'settlement-one'))->assertOk()->assertJsonPath('status', 'posted');
        $settlement = GatewaySettlement::query()->findOrFail($response->json('id'));
        $this->assertDatabaseHas('journal_lines', ['journal_id' => $settlement->journal_id, 'account_id' => $context['accounts']['bank']->id, 'debit' => 97000]);
        $this->assertDatabaseHas('journal_lines', ['journal_id' => $settlement->journal_id, 'account_id' => $context['accounts']['gateway_fees']->id, 'debit' => 3000]);
        $this->assertDatabaseHas('journal_lines', ['journal_id' => $settlement->journal_id, 'account_id' => $context['accounts']['gateway_clearing']->id, 'credit' => 100000]);

        $this->postJson('/api/v1/banking/settlements', [...$payload, 'settlement_reference' => 'SET-BAD', 'net_amount' => 96000], $this->headers($context['company']->id, 'settlement-bad'))->assertUnprocessable()->assertJsonValidationErrors('net_amount');
        $this->postJson('/api/v1/banking/settlements', $payload, $this->headers($context['company']->id, 'settlement-duplicate-reference'))->assertConflict();
    }

    public function test_customer_receipt_from_bank_reuses_ar_payment_engine(): void
    {
        $context = $this->stage6BankingContext();
        $invoiceJournal = Journal::factory()->for($context['company'])->create(['status' => 'posted', 'posting_date' => '2026-09-10', 'created_by' => $context['user']->id, 'posted_by' => $context['user']->id]);
        $invoice = Invoice::factory()->for($context['company'])->for($context['customer'])->create(['status' => 'unpaid', 'total' => 50000, 'balance_due' => 50000, 'amount_paid' => 0, 'journal_id' => $invoiceJournal->id, 'created_by' => $context['user']->id]);
        $transaction = BankTransaction::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $context['bank']->id, 'created_by' => $context['user']->id, 'direction' => 'credit', 'amount' => 50000, 'transaction_date' => '2026-09-20']);

        $this->postJson("/api/v1/banking/transactions/{$transaction->id}/customer-receipt", ['invoice_id' => $invoice->id], $this->headers($context['company']->id, 'receipt-from-bank'))->assertOk();
        $this->assertDatabaseHas('customer_payments', ['invoice_id' => $invoice->id, 'amount' => 50000, 'bank_account_id' => $context['accounts']['bank']->id]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid', 'balance_due' => 0]);
        $this->assertSame('matched', $transaction->fresh()->status->value);
        $this->postJson("/api/v1/banking/transactions/{$transaction->id}/customer-receipt", ['invoice_id' => $invoice->id], $this->headers($context['company']->id, 'receipt-from-bank'))->assertOk();
        $this->assertDatabaseCount('customer_payments', 1);
    }

    public function test_supplier_payment_from_bank_reuses_ap_payment_engine(): void
    {
        $context = $this->stage6BankingContext();
        $billJournal = Journal::factory()->for($context['company'])->create(['status' => 'posted', 'posting_date' => '2026-09-10', 'created_by' => $context['user']->id, 'posted_by' => $context['user']->id]);
        $bill = SupplierBill::factory()->for($context['company'])->for($context['supplier'])->create(['status' => 'unpaid', 'total' => 40000, 'gross_total' => 40000, 'balance_due' => 40000, 'amount_paid' => 0, 'journal_id' => $billJournal->id, 'created_by' => $context['user']->id]);
        $transaction = BankTransaction::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $context['bank']->id, 'created_by' => $context['user']->id, 'direction' => 'debit', 'amount' => 40000, 'transaction_date' => '2026-09-20']);

        $this->postJson("/api/v1/banking/transactions/{$transaction->id}/supplier-payment", ['supplier_bill_id' => $bill->id], $this->headers($context['company']->id, 'payment-from-bank'))->assertOk();
        $this->assertDatabaseHas('supplier_payment_allocations', ['supplier_bill_id' => $bill->id, 'amount' => 40000]);
        $this->assertDatabaseHas('supplier_bills', ['id' => $bill->id, 'status' => 'paid', 'balance_due' => 0]);
        $this->assertSame('matched', $transaction->fresh()->status->value);
    }

    /** @return array<string, string> */
    private function headers(string $companyId, string $key): array
    {
        return ['X-Company-Id' => $companyId, 'Idempotency-Key' => $key];
    }
}
