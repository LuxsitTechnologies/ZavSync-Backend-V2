<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Journal;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use App\Models\PayrollPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollPaymentSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_payments_support_partial_and_full_batch_settlement(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);

        $first = $this->postJson("/api/v1/payroll/batches/{$batch->id}/payments", $this->paymentData($context, 300_000), $this->headers($context['company']->id, 'salary-partial'))->assertCreated();
        $this->assertSame(300_000, $first->json('amount'));
        $this->assertDatabaseHas('payroll_batches', ['id' => $batch->id, 'status' => 'PARTIALLY_PAID']);

        $this->postJson("/api/v1/payroll/batches/{$batch->id}/payments", $this->paymentData($context, 610_000), $this->headers($context['company']->id, 'salary-final'))->assertCreated();
        $this->assertDatabaseHas('payroll_batches', ['id' => $batch->id, 'status' => 'PAID']);
        $this->assertSame(910_000, (int) PayrollPayment::query()->where('payroll_batch_id', $batch->id)->sum('amount'));
    }

    public function test_individual_employee_payment_allocates_only_the_selected_entry(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);
        $entry = PayrollEntry::query()->where('payroll_batch_id', $batch->id)->firstOrFail();

        $response = $this->postJson("/api/v1/payroll/entries/{$entry->id}/payments", $this->paymentData($context, 250_000), $this->headers($context['company']->id, 'employee-partial'))->assertCreated();
        $this->assertSame($entry->id, $response->json('allocations.0.payroll_entry_id'));
        $this->assertDatabaseHas('payroll_payment_allocations', ['payroll_entry_id' => $entry->id, 'amount' => 250_000]);
    }

    public function test_salary_payment_posts_liability_to_bank_and_rejects_overpayment(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);

        $response = $this->postJson("/api/v1/payroll/batches/{$batch->id}/payments", $this->paymentData($context, 910_000), $this->headers($context['company']->id, 'salary-full'))->assertCreated();
        $journal = Journal::query()->with('lines')->findOrFail($response->json('journal_id'));
        $this->assertSame(910_000, (int) $journal->lines->where('account_id', $context['accounts']['payroll_net_payable']->id)->sum('debit'));
        $this->assertSame(910_000, (int) $journal->lines->where('account_id', $context['accounts']['bank']->id)->sum('credit'));

        $this->postJson("/api/v1/payroll/batches/{$batch->id}/payments", $this->paymentData($context, 1), $this->headers($context['company']->id, 'salary-overpay'))->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_BATCH_NOT_POSTED');
        $this->assertDatabaseCount('payroll_payments', 1);
    }

    public function test_payment_idempotency_is_safe_and_conflicts_are_rejected(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);
        $data = $this->paymentData($context, 100_000);

        $first = $this->postJson("/api/v1/payroll/batches/{$batch->id}/payments", $data, $this->headers($context['company']->id, 'payment-retry'))->assertCreated();
        $second = $this->postJson("/api/v1/payroll/batches/{$batch->id}/payments", $data, $this->headers($context['company']->id, 'payment-retry'))->assertOk();
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('payroll_payments', 1);
        $this->postJson("/api/v1/payroll/batches/{$batch->id}/payments", $this->paymentData($context, 99_999), $this->headers($context['company']->id, 'payment-retry'))->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_IDEMPOTENCY_CONFLICT');
    }

    public function test_payment_rejects_foreign_or_currency_mismatched_financial_accounts(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);
        $foreignCompany = Company::factory()->create();
        $foreignAccount = FinancialAccount::factory()->for($foreignCompany)->create();

        $this->postJson("/api/v1/payroll/batches/{$batch->id}/payments", ['financial_account_id' => $foreignAccount->id, 'payment_date' => '2026-09-30', 'amount' => 10_000], $this->headers($context['company']->id, 'foreign-bank'))->assertUnprocessable()->assertJsonValidationErrors('financial_account_id');
        $context['bank']->update(['currency' => 'USD']);
        $this->postJson("/api/v1/payroll/batches/{$batch->id}/payments", $this->paymentData($context, 10_000), $this->headers($context['company']->id, 'wrong-currency'))->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_FINANCIAL_ACCOUNT_INVALID');
        $this->assertDatabaseCount('payroll_payments', 0);
    }

    public function test_statutory_and_other_liabilities_settle_partially_and_fully(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);

        $this->postJson('/api/v1/payroll/liability-settlements', $this->settlementData($context, $batch, 'TAX', 60_000), $this->headers($context['company']->id, 'tax-partial'))->assertCreated();
        $liabilities = $this->getJson('/api/v1/payroll/liabilities?payroll_batch_id='.$batch->id, $this->headers($context['company']->id))->assertOk();
        $tax = collect($liabilities->json('data.rows'))->firstWhere('liability_type', 'TAX');
        $this->assertSame(50_000, $tax['outstanding_amount']);

        $response = $this->postJson('/api/v1/payroll/liability-settlements', $this->settlementData($context, $batch, 'TAX', 50_000), $this->headers($context['company']->id, 'tax-final'))->assertCreated();
        $journal = Journal::query()->with('lines')->findOrFail($response->json('journal_id'));
        $this->assertSame(50_000, (int) $journal->lines->where('account_id', $context['accounts']['payroll_tax_payable']->id)->sum('debit'));
        $this->assertSame(50_000, (int) $journal->lines->where('account_id', $context['accounts']['bank']->id)->sum('credit'));
        $this->assertDatabaseCount('payroll_liability_settlements', 2);
    }

    public function test_liability_oversettlement_and_idempotency_conflicts_are_rejected(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);
        $data = $this->settlementData($context, $batch, 'EMPLOYEE_CONTRIBUTION', 30_000);

        $first = $this->postJson('/api/v1/payroll/liability-settlements', $data, $this->headers($context['company']->id, 'liability-retry'))->assertCreated();
        $second = $this->postJson('/api/v1/payroll/liability-settlements', $data, $this->headers($context['company']->id, 'liability-retry'))->assertOk();
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->postJson('/api/v1/payroll/liability-settlements', $this->settlementData($context, $batch, 'EMPLOYEE_CONTRIBUTION', 1), $this->headers($context['company']->id, 'liability-over'))->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_LIABILITY_OVERSETTLEMENT');
        $this->postJson('/api/v1/payroll/liability-settlements', $this->settlementData($context, $batch, 'EMPLOYEE_CONTRIBUTION', 29_999), $this->headers($context['company']->id, 'liability-retry'))->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_IDEMPOTENCY_CONFLICT');
    }

    public function test_bank_matching_treats_payroll_records_as_evidence_without_duplicate_journals(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);
        $payment = $this->postJson("/api/v1/payroll/batches/{$batch->id}/payments", $this->paymentData($context, 100_000), $this->headers($context['company']->id, 'bank-evidence'))->assertCreated();
        $journalCount = Journal::query()->count();
        $transaction = BankTransaction::factory()->for($context['bank'], 'financialAccount')->create(['company_id' => $context['company']->id, 'direction' => 'debit', 'amount' => 100_000, 'currency' => 'PKR', 'transaction_date' => '2026-09-30', 'created_by' => $context['user']->id]);

        $suggestions = $this->getJson("/api/v1/banking/transactions/{$transaction->id}/suggestions", $this->headers($context['company']->id))->assertOk();
        $this->assertContains($payment->json('id'), collect($suggestions->json('data'))->pluck('id')->all());
        $this->postJson("/api/v1/banking/transactions/{$transaction->id}/match", ['matchable_type' => 'payroll_payment', 'matchable_id' => $payment->json('id'), 'amount' => 100_000], $this->headers($context['company']->id, 'payroll-match'))->assertOk();
        $this->assertSame($journalCount, Journal::query()->count());
        $this->assertDatabaseHas('bank_transactions', ['id' => $transaction->id, 'status' => 'matched']);
    }

    public function test_posted_batch_cannot_be_reversed_after_any_payment_or_settlement(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);
        $this->postJson("/api/v1/payroll/batches/{$batch->id}/payments", $this->paymentData($context, 1), $this->headers($context['company']->id, 'locks-reversal'))->assertCreated();

        $this->postJson("/api/v1/payroll/batches/{$batch->id}/reverse", ['posting_date' => '2026-09-30', 'reason' => 'Invalid after settlement'], $this->headers($context['company']->id, 'reverse-denied'))->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_CORRECTION_BLOCKED');
    }

    /** @param array<string, mixed> $context */
    private function postedBatch(array $context): PayrollBatch
    {
        $batchId = $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $context['payrollPeriod']->id], $this->headers($context['company']->id))->assertCreated()->json('id');
        foreach (['calculate', 'review', 'approve'] as $action) {
            $this->postJson("/api/v1/payroll/batches/{$batchId}/{$action}", [], $this->headers($context['company']->id))->assertOk();
        }
        $this->postJson("/api/v1/payroll/batches/{$batchId}/post", [], $this->headers($context['company']->id, 'post-'.$batchId))->assertOk();

        return PayrollBatch::query()->findOrFail($batchId);
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function paymentData(array $context, int $amount): array
    {
        return ['financial_account_id' => $context['bank']->id, 'payment_date' => '2026-09-30', 'amount' => $amount, 'reference' => 'PAYROLL-TRANSFER'];
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function settlementData(array $context, PayrollBatch $batch, string $type, int $amount): array
    {
        return ['payroll_batch_id' => $batch->id, 'liability_type' => $type, 'financial_account_id' => $context['bank']->id, 'payment_date' => '2026-09-30', 'amount' => $amount];
    }

    /** @return array<string, string> */
    private function headers(string $companyId, ?string $idempotencyKey = null): array
    {
        return array_filter(['X-Company-Id' => $companyId, 'Accept' => 'application/json', 'Idempotency-Key' => $idempotencyKey]);
    }
}
