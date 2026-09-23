<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_payment_posts_bank_and_receivable_and_updates_outstanding(): void
    {
        $context = $this->stage3AccountingContext();
        $invoice = $this->postedInvoice($context);

        $response = $this->postJson("/api/v1/accounting/receivables/invoices/{$invoice->id}/payments", $this->payment($context, 50000), $this->headers($context['company']->id, 'payment-partial'));

        $response->assertOk()->assertJsonPath('accounting_status', 'partial')->assertJsonPath('amount_paid', 50000)->assertJsonPath('balance_due', 68000);
        $payment = CustomerPayment::query()->firstOrFail();
        $this->assertDatabaseHas('journal_lines', ['journal_id' => $payment->journal_id, 'account_id' => $context['accounts']['bank']->id, 'debit' => 50000, 'credit' => 0]);
        $this->assertDatabaseHas('journal_lines', ['journal_id' => $payment->journal_id, 'account_id' => $context['accounts']['accounts_receivable']->id, 'debit' => 0, 'credit' => 50000]);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $payment->id, 'action' => 'create_and_post', 'module' => 'accounts_receivable']);
    }

    public function test_full_payment_changes_invoice_to_paid(): void
    {
        $context = $this->stage3AccountingContext();
        $invoice = $this->postedInvoice($context);

        $this->postJson("/api/v1/accounting/receivables/invoices/{$invoice->id}/payments", $this->payment($context, 118000), $this->headers($context['company']->id, 'payment-full'))
            ->assertOk()->assertJsonPath('accounting_status', 'paid')->assertJsonPath('balance_due', 0);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid', 'amount_paid' => 118000, 'balance_due' => 0]);
    }

    public function test_payment_cannot_exceed_outstanding_balance(): void
    {
        $context = $this->stage3AccountingContext();
        $invoice = $this->postedInvoice($context);

        $this->postJson("/api/v1/accounting/receivables/invoices/{$invoice->id}/payments", $this->payment($context, 118001), $this->headers($context['company']->id, 'payment-too-large'))->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('customer_payments', 0);
        $this->assertDatabaseCount('journals', 1);
    }

    public function test_payment_retry_is_idempotent_and_payload_mismatch_returns_409(): void
    {
        $context = $this->stage3AccountingContext();
        $invoice = $this->postedInvoice($context);
        $headers = $this->headers($context['company']->id, 'payment-repeat');

        $first = $this->postJson("/api/v1/accounting/receivables/invoices/{$invoice->id}/payments", $this->payment($context, 50000), $headers)->assertOk();
        $second = $this->postJson("/api/v1/accounting/receivables/invoices/{$invoice->id}/payments", $this->payment($context, 50000), $headers)->assertOk();
        $this->postJson("/api/v1/accounting/receivables/invoices/{$invoice->id}/payments", $this->payment($context, 40000), $headers)->assertConflict();

        $this->assertSame($first->json('amount_paid'), $second->json('amount_paid'));
        $this->assertDatabaseCount('customer_payments', 1);
        $this->assertDatabaseCount('journals', 2);
    }

    public function test_locked_period_rolls_back_payment_and_journal(): void
    {
        $context = $this->stage3AccountingContext();
        $invoice = $this->postedInvoice($context);
        AccountingPeriod::query()->where('company_id', $context['company']->id)->update(['status' => 'closed']);

        $this->postJson("/api/v1/accounting/receivables/invoices/{$invoice->id}/payments", $this->payment($context, 50000), $this->headers($context['company']->id, 'payment-locked'))->assertUnprocessable()->assertJsonValidationErrors('posting_date');

        $this->assertDatabaseCount('customer_payments', 0);
        $this->assertDatabaseCount('journals', 1);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'amount_paid' => 0, 'balance_due' => 118000]);
    }

    public function test_company_cannot_pay_another_company_invoice(): void
    {
        $owner = $this->stage3AccountingContext();
        $invoice = $this->postedInvoice($owner);
        $outsider = $this->stage3AccountingContext();

        $this->postJson("/api/v1/accounting/receivables/invoices/{$invoice->id}/payments", $this->payment($outsider, 50000), $this->headers($outsider['company']->id, 'payment-cross-company'))
            ->assertNotFound();

        $this->assertDatabaseCount('customer_payments', 0);
        $this->assertDatabaseCount('journals', 1);
    }

    /** @param array<string, mixed> $context */
    private function postedInvoice(array $context): Invoice
    {
        $payload = ['customer_id' => $context['customer']->id, 'invoice_date' => '2026-09-22', 'due_date' => '2026-10-22', 'currency' => 'PKR', 'lines' => [['description' => 'Services', 'quantity_milli' => 1000, 'unit' => 'unit', 'unit_price' => 100000, 'discount' => 0, 'tax_rate_bps' => 1800, 'sales_type' => 'Standardized Goods']]];
        $invoiceId = $this->postJson('/api/v1/accounting/invoices', $payload, $this->headers($context['company']->id, 'invoice-'.fake()->uuid()))->assertCreated()->json('id');
        $this->postJson("/api/v1/accounting/invoices/$invoiceId/post", [], ['X-Company-Id' => $context['company']->id])->assertOk();

        return Invoice::query()->findOrFail($invoiceId);
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function payment(array $context, int $amount): array
    {
        return ['amount' => $amount, 'payment_date' => '2026-09-23', 'method' => 'bank_transfer', 'bank_account_id' => $context['accounts']['bank']->id, 'reference' => 'TRX-100', 'note' => 'Customer receipt'];
    }

    /** @return array<string, string> */
    private function headers(string $companyId, string $key): array
    {
        return ['X-Company-Id' => $companyId, 'Idempotency-Key' => $key];
    }
}
