<?php

namespace Tests\Feature\Accounting;

use App\Models\BankReconciliation;
use App\Models\BankTransaction;
use App\Models\CustomerPayment;
use App\Models\Journal;
use App\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_customer_payment_matches_without_creating_duplicate_journal_and_can_unmatch(): void
    {
        $context = $this->stage6BankingContext();
        $journal = Journal::factory()->for($context['company'])->create(['source' => 'customer_payment', 'status' => 'posted', 'posting_date' => '2026-09-20', 'created_by' => $context['user']->id, 'posted_by' => $context['user']->id]);
        $payment = CustomerPayment::factory()->create(['company_id' => $context['company']->id, 'customer_id' => $context['customer']->id, 'bank_account_id' => $context['accounts']['bank']->id, 'journal_id' => $journal->id, 'created_by' => $context['user']->id, 'amount' => 10000, 'payment_date' => '2026-09-20', 'number' => 'RCPT-2026-0001']);
        $transaction = BankTransaction::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $context['bank']->id, 'created_by' => $context['user']->id, 'direction' => 'credit', 'amount' => 10000, 'transaction_date' => '2026-09-20', 'bank_reference' => $payment->number]);
        $headers = $this->headers($context['company']->id, 'match-customer');
        $this->getJson("/api/v1/banking/transactions/{$transaction->id}/suggestions", $headers)->assertOk()->assertJsonPath('data.0.confidence', 'exact');
        $response = $this->postJson("/api/v1/banking/transactions/{$transaction->id}/match", ['matchable_type' => 'customer_payment', 'matchable_id' => $payment->id], $headers)->assertOk();
        $matchId = $response->json('data.id');

        $this->assertDatabaseCount('journals', 1);
        $this->assertSame('matched', $transaction->fresh()->status->value);
        $duplicateEvidence = BankTransaction::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $context['bank']->id, 'created_by' => $context['user']->id, 'direction' => 'credit', 'amount' => 10000, 'transaction_date' => '2026-09-20']);
        $this->postJson("/api/v1/banking/transactions/{$duplicateEvidence->id}/match", ['matchable_type' => 'customer_payment', 'matchable_id' => $payment->id], $this->headers($context['company']->id, 'duplicate-payment-match'))->assertUnprocessable()->assertJsonValidationErrors('matchable_id');
        $this->postJson("/api/v1/banking/matches/{$matchId}/unmatch", ['reason' => 'Wrong receipt'], $headers)->assertOk();
        $this->assertSame('unmatched', $transaction->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $matchId, 'action' => 'unmatch']);
    }

    public function test_wrong_direction_and_amount_are_rejected(): void
    {
        $context = $this->stage6BankingContext();
        $payment = CustomerPayment::factory()->create(['company_id' => $context['company']->id, 'customer_id' => $context['customer']->id, 'bank_account_id' => $context['accounts']['bank']->id, 'created_by' => $context['user']->id, 'amount' => 10000]);
        $transaction = BankTransaction::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $context['bank']->id, 'created_by' => $context['user']->id, 'direction' => 'debit', 'amount' => 10000]);
        $this->postJson("/api/v1/banking/transactions/{$transaction->id}/match", ['matchable_type' => 'customer_payment', 'matchable_id' => $payment->id, 'amount' => 11000], $this->headers($context['company']->id, 'wrong-match'))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/banking/transactions/{$transaction->id}/match", ['matchable_type' => 'customer_payment', 'matchable_id' => $payment->id, 'amount' => 10000], $this->headers($context['company']->id, 'wrong-direction'))->assertUnprocessable()->assertJsonValidationErrors('matchable_id');
    }

    public function test_existing_supplier_payment_matches_without_duplicate_journal(): void
    {
        $context = $this->stage6BankingContext();
        $journal = Journal::factory()->for($context['company'])->create(['source' => 'supplier_payment', 'status' => 'posted', 'posting_date' => '2026-09-20', 'created_by' => $context['user']->id, 'posted_by' => $context['user']->id]);
        $payment = SupplierPayment::factory()->create(['company_id' => $context['company']->id, 'supplier_id' => $context['supplier']->id, 'bank_account_id' => $context['accounts']['bank']->id, 'journal_id' => $journal->id, 'created_by' => $context['user']->id, 'amount' => 15000, 'payment_date' => '2026-09-20', 'posting_date' => '2026-09-20']);
        $transaction = BankTransaction::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $context['bank']->id, 'created_by' => $context['user']->id, 'direction' => 'debit', 'amount' => 15000, 'transaction_date' => '2026-09-20']);

        $this->postJson("/api/v1/banking/transactions/{$transaction->id}/match", ['matchable_type' => 'supplier_payment', 'matchable_id' => $payment->id], $this->headers($context['company']->id, 'supplier-match'))->assertOk();
        $this->assertDatabaseCount('journals', 1);
        $this->assertSame('matched', $transaction->fresh()->status->value);
    }

    public function test_generic_bank_journal_is_suggested_and_tenant_isolation_hides_transaction(): void
    {
        $context = $this->stage6BankingContext();
        $journal = Journal::factory()->for($context['company'])->create(['reference' => 'BANK-REF-9', 'status' => 'posted', 'posting_date' => '2026-09-20', 'created_by' => $context['user']->id, 'posted_by' => $context['user']->id]);
        $journal->lines()->createMany([
            ['account_id' => $context['accounts']['bank']->id, 'debit' => 9000, 'credit' => 0],
            ['account_id' => $context['accounts']['interest_income']->id, 'debit' => 0, 'credit' => 9000],
        ]);
        $transaction = BankTransaction::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $context['bank']->id, 'created_by' => $context['user']->id, 'direction' => 'credit', 'amount' => 9000, 'transaction_date' => '2026-09-20', 'bank_reference' => 'BANK-REF-9']);

        $this->getJson("/api/v1/banking/transactions/{$transaction->id}/suggestions", $this->headers($context['company']->id, 'journal-suggestions'))->assertOk()->assertJsonFragment(['type' => 'journal', 'id' => $journal->id, 'confidence' => 'exact']);
        [, $otherCompany] = $this->actingAsCompanyUser(['banking.view', 'banking.reconcile']);
        $this->getJson("/api/v1/banking/transactions/{$transaction->id}/suggestions", $this->headers($otherCompany->id, 'foreign-suggestions'))->assertNotFound();
    }

    public function test_balanced_reconciliation_completes_reopens_and_is_audited(): void
    {
        $context = $this->stage6BankingContext();
        $payload = ['financial_account_id' => $context['bank']->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'statement_opening_balance' => 0, 'statement_closing_balance' => 0];
        $headers = $this->headers($context['company']->id, 'reconciliation-one');
        $response = $this->postJson('/api/v1/banking/reconciliations', $payload, $headers)->assertCreated();
        $id = $response->json('id');
        $this->postJson("/api/v1/banking/reconciliations/{$id}/complete", [], $headers)->assertOk()->assertJsonPath('status', 'completed');
        $this->postJson("/api/v1/banking/reconciliations/{$id}/reopen", ['reason' => 'Late statement item'], $headers)->assertOk()->assertJsonPath('status', 'reopened');
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $id, 'action' => 'reopen_reconciliation']);
    }

    public function test_completed_reconciliation_prevents_destructive_unmatch(): void
    {
        $context = $this->stage6BankingContext();
        $payment = CustomerPayment::factory()->create(['company_id' => $context['company']->id, 'customer_id' => $context['customer']->id, 'bank_account_id' => $context['accounts']['bank']->id, 'created_by' => $context['user']->id, 'amount' => 1000]);
        $transaction = BankTransaction::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $context['bank']->id, 'created_by' => $context['user']->id, 'direction' => 'credit', 'amount' => 1000, 'transaction_date' => '2026-09-20']);
        $reconciliation = BankReconciliation::factory()->create(['company_id' => $context['company']->id, 'financial_account_id' => $context['bank']->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'statement_closing_balance' => 0, 'created_by' => $context['user']->id]);
        $headers = $this->headers($context['company']->id, 'locked-match');
        $match = $this->postJson("/api/v1/banking/transactions/{$transaction->id}/match", ['bank_reconciliation_id' => $reconciliation->id, 'matchable_type' => 'customer_payment', 'matchable_id' => $payment->id], $headers)->assertOk();
        $this->postJson("/api/v1/banking/reconciliations/{$reconciliation->id}/complete", [], $headers)->assertOk();
        $this->postJson('/api/v1/banking/matches/'.$match->json('data.id').'/unmatch', ['reason' => 'Attempt destructive change'], $headers)->assertUnprocessable()->assertJsonValidationErrors('match');
    }

    /** @return array<string, string> */
    private function headers(string $companyId, string $key): array
    {
        return ['X-Company-Id' => $companyId, 'Idempotency-Key' => $key];
    }
}
