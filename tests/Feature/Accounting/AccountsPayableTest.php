<?php

namespace Tests\Feature\Accounting;

use App\Models\SupplierBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountsPayableTest extends TestCase
{
    use RefreshDatabase;

    public function test_aging_uses_due_date_and_historical_payment_dates(): void
    {
        $context = $this->stage4AccountingContext();
        $bill = $this->postedBill($context, 'AGING-1', '2026-08-15');
        $this->postJson("/api/v1/accounting/payables/bills/{$bill->id}/payments", ['amount' => 30000, 'payment_date' => '2026-09-23', 'posting_date' => '2026-09-23', 'method' => 'bank_transfer', 'bank_account_id' => $context['accounts']['bank']->id], $this->headers($context['company']->id, 'aging-payment'))->assertOk();

        $this->getJson('/api/v1/accounting/payables/aging?as_of=2026-09-20', ['X-Company-Id' => $context['company']->id])->assertOk()->assertJsonPath('0.d31_60', 100000)->assertJsonPath('0.total', 100000);
        $this->getJson('/api/v1/accounting/payables/aging?as_of=2026-09-24', ['X-Company-Id' => $context['company']->id])->assertOk()->assertJsonPath('0.d31_60', 70000)->assertJsonPath('0.total', 70000);
    }

    public function test_statement_has_opening_running_and_closing_payable_balances(): void
    {
        $context = $this->stage4AccountingContext();
        $bill = $this->postedBill($context, 'STATEMENT-1', '2026-09-30');
        $this->postJson("/api/v1/accounting/payables/bills/{$bill->id}/payments", ['amount' => 25000, 'payment_date' => '2026-09-23', 'posting_date' => '2026-09-23', 'method' => 'bank_transfer', 'bank_account_id' => $context['accounts']['bank']->id], $this->headers($context['company']->id, 'statement-payment'))->assertOk();

        $this->getJson("/api/v1/accounting/payables/suppliers/{$context['supplier']->id}/statement?from=2026-09-23&to=2026-09-30", ['X-Company-Id' => $context['company']->id])
            ->assertOk()->assertJsonPath('opening_balance', 100000)->assertJsonPath('lines.0.type', 'payment')->assertJsonPath('lines.0.balance', 75000)->assertJsonPath('closing_balance', 75000);
    }

    public function test_void_reversal_appears_in_ledger_and_removes_balance(): void
    {
        $context = $this->stage4AccountingContext();
        $bill = $this->postedBill($context, 'VOID-1', '2026-09-30');
        $this->postJson("/api/v1/accounting/payables/bills/{$bill->id}/void", ['posting_date' => '2026-09-24', 'reason' => 'Cancelled'], ['X-Company-Id' => $context['company']->id])->assertOk();

        $this->getJson("/api/v1/accounting/payables/suppliers/{$context['supplier']->id}/ledger?from=2026-09-01&to=2026-09-30", ['X-Company-Id' => $context['company']->id])
            ->assertOk()->assertJsonCount(2, 'lines')->assertJsonPath('lines.1.type', 'bill_void')->assertJsonPath('closing_balance', 0);
    }

    /** @param array<string,mixed> $context */
    private function postedBill(array $context, string $reference, string $dueDate): SupplierBill
    {
        $payload = ['supplier_id' => $context['supplier']->id, 'supplier_invoice_number' => $reference, 'bill_date' => '2026-08-01', 'posting_date' => '2026-09-10', 'due_date' => $dueDate, 'currency' => 'PKR', 'lines' => [['description' => 'Service', 'procurement_type' => 'service', 'quantity_milli' => 1000, 'unit' => 'unit', 'unit_price' => 100000, 'tax_rate_bps' => 0, 'withholding_rate_bps' => 0, 'expense_account_id' => $context['accounts']['purchase_expense']->id]]];
        $id = $this->postJson('/api/v1/accounting/payables/bills', $payload, $this->headers($context['company']->id, 'bill-'.$reference))->assertCreated()->json('id');
        $this->postJson("/api/v1/accounting/payables/bills/$id/post", [], ['X-Company-Id' => $context['company']->id])->assertOk();

        return SupplierBill::query()->findOrFail($id);
    }

    /** @return array<string,string> */
    private function headers(string $companyId, string $key): array
    {
        return ['X-Company-Id' => $companyId, 'Idempotency-Key' => $key];
    }
}
