<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\SupplierBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_multiple_and_full_payments_update_bill_and_create_balanced_journals(): void
    {
        $context = $this->stage4AccountingContext();
        $bill = $this->postedBill($context);

        $first = $this->postJson("/api/v1/accounting/payables/bills/{$bill->id}/payments", $this->payment($context, 40000), $this->headers($context['company']->id, 'payment-one'));
        $first->assertOk()->assertJsonPath('accounting_status', 'partial')->assertJsonPath('amount_paid', 40000)->assertJsonPath('balance_due', 73000);
        $second = $this->postJson("/api/v1/accounting/payables/bills/{$bill->id}/payments", $this->payment($context, 73000), $this->headers($context['company']->id, 'payment-two'));
        $second->assertOk()->assertJsonPath('accounting_status', 'paid')->assertJsonPath('balance_due', 0);
        $this->assertDatabaseCount('supplier_payments', 2);
        $this->assertDatabaseCount('supplier_payment_allocations', 2);
        $this->assertDatabaseCount('journals', 3);
        $this->assertSame(40000, (int) $bill->fresh()->allocations()->firstOrFail()->payment->journal->lines()->sum('debit'));
    }

    public function test_payment_is_idempotent_and_overpayment_is_rejected(): void
    {
        $context = $this->stage4AccountingContext();
        $bill = $this->postedBill($context);
        $headers = $this->headers($context['company']->id, 'payment-repeat');
        $payload = $this->payment($context, 10000);
        $this->postJson("/api/v1/accounting/payables/bills/{$bill->id}/payments", $payload, $headers)->assertOk();
        $this->postJson("/api/v1/accounting/payables/bills/{$bill->id}/payments", $payload, $headers)->assertOk()->assertJsonPath('amount_paid', 10000);
        $this->postJson("/api/v1/accounting/payables/bills/{$bill->id}/payments", $this->payment($context, 200000), $this->headers($context['company']->id, 'overpay'))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('supplier_payments', 1);
    }

    public function test_locked_period_and_cross_company_payment_roll_back(): void
    {
        $context = $this->stage4AccountingContext();
        $bill = $this->postedBill($context);
        AccountingPeriod::query()->where('company_id', $context['company']->id)->update(['status' => 'closed']);
        $this->postJson("/api/v1/accounting/payables/bills/{$bill->id}/payments", $this->payment($context, 10000), $this->headers($context['company']->id, 'locked-payment'))->assertUnprocessable()->assertJsonValidationErrors('posting_date');
        $this->assertDatabaseCount('supplier_payments', 0);

        $other = $this->stage4AccountingContext();
        $this->postJson("/api/v1/accounting/payables/bills/{$bill->id}/payments", $this->payment($other, 10000), $this->headers($other['company']->id, 'cross-payment'))->assertNotFound();
    }

    /** @param array<string,mixed> $context */
    private function postedBill(array $context): SupplierBill
    {
        $payload = ['supplier_id' => $context['supplier']->id, 'supplier_invoice_number' => 'PAYABLE-1', 'bill_date' => '2026-09-22', 'posting_date' => '2026-09-22', 'due_date' => '2026-09-30', 'currency' => 'PKR', 'lines' => [['description' => 'Service', 'procurement_type' => 'service', 'quantity_milli' => 1000, 'unit' => 'unit', 'unit_price' => 100000, 'tax_rate_bps' => 1800, 'withholding_rate_bps' => 500, 'expense_account_id' => $context['accounts']['purchase_expense']->id]]];
        $id = $this->postJson('/api/v1/accounting/payables/bills', $payload, $this->headers($context['company']->id, 'payment-bill'))->assertCreated()->json('id');
        $this->postJson("/api/v1/accounting/payables/bills/$id/post", [], ['X-Company-Id' => $context['company']->id])->assertOk();

        return SupplierBill::query()->findOrFail($id);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function payment(array $context, int $amount): array
    {
        return ['amount' => $amount, 'payment_date' => '2026-09-23', 'posting_date' => '2026-09-23', 'method' => 'bank_transfer', 'bank_account_id' => $context['accounts']['bank']->id, 'reference' => 'BANK-1'];
    }

    /** @return array<string,string> */
    private function headers(string $companyId, string $key): array
    {
        return ['X-Company-Id' => $companyId, 'Idempotency-Key' => $key];
    }
}
