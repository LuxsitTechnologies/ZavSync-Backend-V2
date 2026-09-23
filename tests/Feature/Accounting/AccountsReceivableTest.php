<?php

namespace Tests\Feature\Accounting;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Journal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountsReceivableTest extends TestCase
{
    use RefreshDatabase;

    public function test_statement_calculates_opening_movement_and_closing_balance(): void
    {
        $context = $this->stage3AccountingContext();
        $first = $this->postedInvoice($context, '2026-01-01', '2026-01-31', 10000, 8000);
        $this->payment($context, $first, '2026-01-15', 2000, 1);
        $second = $this->postedInvoice($context, '2026-02-10', '2026-03-10', 5000, 4000, 2);
        $this->payment($context, $second, '2026-02-20', 1000, 2);

        $response = $this->getJson("/api/v1/accounting/receivables/customers/{$context['customer']->id}/statement?from=2026-02-01&to=2026-02-28", ['X-Company-Id' => $context['company']->id]);

        $response->assertOk()->assertJsonPath('opening_balance', 8000)->assertJsonPath('closing_balance', 12000)->assertJsonCount(2, 'lines')->assertJsonPath('lines.0.debit', 5000)->assertJsonPath('lines.1.credit', 1000)->assertJsonPath('lines.1.balance', 12000);
    }

    public function test_receivable_ledger_excludes_drafts_and_includes_only_posted_financial_activity(): void
    {
        $context = $this->stage3AccountingContext();
        Invoice::factory()->for($context['company'])->for($context['customer'])->create(['invoice_date' => '2026-09-01', 'due_date' => '2026-09-30', 'total' => 99999, 'balance_due' => 99999, 'created_by' => $context['user']->id]);
        $posted = $this->postedInvoice($context, '2026-09-02', '2026-09-30', 10000, 10000);

        $response = $this->getJson("/api/v1/accounting/receivables/customers/{$context['customer']->id}/ledger?from=2026-09-01&to=2026-09-30", ['X-Company-Id' => $context['company']->id]);

        $response->assertOk()->assertJsonCount(1, 'lines')->assertJsonPath('lines.0.id', $posted->id)->assertJsonPath('closing_balance', 10000);
    }

    public function test_aging_uses_outstanding_amount_and_places_each_due_date_in_authoritative_bucket(): void
    {
        $context = $this->stage3AccountingContext();
        $dueDates = ['2026-09-30', '2026-09-10', '2026-08-15', '2026-07-15', '2026-06-01'];
        foreach ($dueDates as $index => $dueDate) {
            $invoice = $this->postedInvoice($context, '2026-05-01', $dueDate, 10000, 7500, $index + 1);
            $this->payment($context, $invoice, '2026-09-01', 2500, $index + 1);
        }

        $response = $this->getJson('/api/v1/accounting/receivables/aging?as_of=2026-09-23', ['X-Company-Id' => $context['company']->id]);

        $response->assertOk()->assertJsonCount(1)->assertJsonPath('0.current', 7500)->assertJsonPath('0.d1_30', 7500)->assertJsonPath('0.d31_60', 7500)->assertJsonPath('0.d61_90', 7500)->assertJsonPath('0.d90_plus', 7500)->assertJsonPath('0.total', 37500);
    }

    public function test_overdue_filter_and_status_are_calculated_from_due_date_and_balance(): void
    {
        $context = $this->stage3AccountingContext();
        $overdue = $this->postedInvoice($context, '2026-08-01', '2026-08-31', 10000, 10000);
        $this->postedInvoice($context, '2026-09-01', '2026-09-30', 20000, 20000, 2);

        $response = $this->getJson('/api/v1/accounting/receivables/invoices?overdue_only=1', ['X-Company-Id' => $context['company']->id]);

        $response->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $overdue->id)->assertJsonPath('0.status', 'overdue')->assertJsonPath('0.outstanding', 10000);
    }

    public function test_customer_outstanding_and_all_receivable_endpoints_are_tenant_scoped(): void
    {
        $owner = $this->stage3AccountingContext();
        $this->postedInvoice($owner, '2026-09-01', '2026-09-30', 12000, 12000);
        $outsider = $this->stage3AccountingContext();

        $this->getJson("/api/v1/accounting/receivables/customers/{$owner['customer']->id}/statement?from=2026-01-01&to=2026-12-31", ['X-Company-Id' => $outsider['company']->id])->assertNotFound();
        $this->getJson('/api/v1/accounting/receivables/invoices', ['X-Company-Id' => $outsider['company']->id])->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/accounting/receivables/aging?as_of=2026-09-23', ['X-Company-Id' => $outsider['company']->id])->assertOk()->assertExactJson([]);
        Sanctum::actingAs($owner['user']);
        $this->getJson('/api/v1/accounting/receivables/customers', ['X-Company-Id' => $owner['company']->id])->assertOk()->assertJsonPath('0.outstanding', 12000);
    }

    public function test_statement_uses_reversal_journal_posting_date_for_void_adjustment(): void
    {
        $context = $this->stage3AccountingContext();
        $invoice = $this->postedInvoice($context, '2026-09-01', '2026-09-15', 10000, 10000);
        $reversal = Journal::factory()->for($context['company'])->create([
            'sequence' => 500, 'number' => 'JV-2026-0500', 'posting_date' => '2026-09-20', 'status' => 'posted',
            'posted_by' => $context['user']->id, 'posted_at' => now(), 'created_by' => $context['user']->id,
        ]);
        $invoice->update(['status' => 'void', 'balance_due' => 0, 'reversal_journal_id' => $reversal->id, 'voided_at' => now()]);

        $before = $this->getJson("/api/v1/accounting/receivables/customers/{$context['customer']->id}/statement?from=2026-09-01&to=2026-09-19", ['X-Company-Id' => $context['company']->id]);
        $after = $this->getJson("/api/v1/accounting/receivables/customers/{$context['customer']->id}/statement?from=2026-09-01&to=2026-09-20", ['X-Company-Id' => $context['company']->id]);

        $before->assertOk()->assertJsonCount(1, 'lines')->assertJsonPath('closing_balance', 10000);
        $after->assertOk()->assertJsonCount(2, 'lines')->assertJsonPath('lines.1.date', '2026-09-20')->assertJsonPath('closing_balance', 0);
    }

    /** @param array<string, mixed> $context */
    private function postedInvoice(array $context, string $invoiceDate, string $dueDate, int $total, int $balance, int $sequence = 1): Invoice
    {
        $journal = Journal::factory()->for($context['company'])->create(['sequence' => 100 + $sequence, 'number' => sprintf('JV-2026-%04d', 100 + $sequence), 'status' => 'posted', 'posted_by' => $context['user']->id, 'posted_at' => $invoiceDate, 'created_by' => $context['user']->id]);

        return Invoice::factory()->for($context['company'])->for($context['customer'])->create([
            'sequence' => $sequence, 'invoice_number' => sprintf('INV-2026-%04d', $sequence), 'invoice_date' => $invoiceDate,
            'due_date' => $dueDate, 'status' => $balance === 0 ? 'paid' : ($balance === $total ? 'unpaid' : 'partial'),
            'subtotal' => $total, 'taxable_amount' => $total, 'sales_tax' => 0, 'total' => $total,
            'amount_paid' => $total - $balance, 'balance_due' => $balance, 'journal_id' => $journal->id,
            'created_by' => $context['user']->id, 'posted_by' => $context['user']->id, 'posted_at' => $invoiceDate,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function payment(array $context, Invoice $invoice, string $date, int $amount, int $sequence): CustomerPayment
    {
        $journal = Journal::factory()->for($context['company'])->create(['sequence' => 200 + $sequence, 'number' => sprintf('JV-2026-%04d', 200 + $sequence), 'status' => 'posted', 'posted_by' => $context['user']->id, 'posted_at' => $date, 'created_by' => $context['user']->id]);

        return CustomerPayment::factory()->for($context['company'])->for($context['customer'])->for($invoice)->create([
            'sequence' => $sequence, 'number' => sprintf('RCPT-2026-%04d', $sequence), 'payment_date' => $date,
            'amount' => $amount, 'bank_account_id' => $context['accounts']['bank']->id, 'journal_id' => $journal->id,
            'created_by' => $context['user']->id,
        ]);
    }
}
