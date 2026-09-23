<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\PurchaseOrder;
use App\Models\SupplierBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierBillLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_draft_calculates_tax_and_withholding_without_gl_or_ap(): void
    {
        $context = $this->stage4AccountingContext();
        $response = $this->postJson('/api/v1/accounting/payables/bills', $this->payload($context), $this->headers($context['company']->id, 'direct-bill'));

        $response->assertCreated()->assertJsonPath('accounting_status', 'draft')->assertJsonPath('subtotal', 100000)
            ->assertJsonPath('purchase_tax', 18000)->assertJsonPath('withholding_tax', 5000)->assertJsonPath('gross_total', 118000)->assertJsonPath('total', 113000);
        $this->assertDatabaseCount('journals', 0);
        $this->getJson('/api/v1/accounting/payables/open-bills', ['X-Company-Id' => $context['company']->id])->assertOk()->assertExactJson([]);
    }

    public function test_posting_creates_balanced_mapped_journal_and_is_idempotent(): void
    {
        $context = $this->stage4AccountingContext();
        $billId = $this->createBill($context, 'posted-bill');

        $first = $this->postJson("/api/v1/accounting/payables/bills/$billId/post", [], ['X-Company-Id' => $context['company']->id])->assertOk()->assertJsonPath('accounting_status', 'unpaid');
        $second = $this->postJson("/api/v1/accounting/payables/bills/$billId/post", [], ['X-Company-Id' => $context['company']->id])->assertOk();
        $this->assertSame($first->json('journal_id'), $second->json('journal_id'));
        $bill = SupplierBill::query()->findOrFail($billId);
        $this->assertSame(118000, $bill->journal->lines()->sum('debit'));
        $this->assertSame(118000, $bill->journal->lines()->sum('credit'));
        $this->assertDatabaseHas('journal_lines', ['account_id' => $context['accounts']['accounts_payable']->id, 'credit' => 113000]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $context['accounts']['purchase_tax_recoverable']->id, 'debit' => 18000]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $context['accounts']['withholding_tax_payable']->id, 'credit' => 5000]);
        $this->assertDatabaseCount('journals', 1);
    }

    public function test_missing_mapping_and_closed_period_roll_back_posting(): void
    {
        $context = $this->stage4AccountingContext();
        $first = $this->createBill($context, 'missing-map', ['supplier_invoice_number' => 'MAP-1']);
        AccountMapping::query()->where('company_id', $context['company']->id)->where('key', 'purchase_tax_recoverable')->delete();
        $this->postJson("/api/v1/accounting/payables/bills/$first/post", [], ['X-Company-Id' => $context['company']->id])->assertUnprocessable()->assertJsonValidationErrors('account_mappings');
        $this->assertDatabaseHas('supplier_bills', ['id' => $first, 'status' => 'draft', 'journal_id' => null]);

        AccountMapping::query()->create(['company_id' => $context['company']->id, 'key' => 'purchase_tax_recoverable', 'account_id' => $context['accounts']['purchase_tax_recoverable']->id, 'updated_by' => $context['user']->id]);
        AccountingPeriod::query()->where('company_id', $context['company']->id)->update(['status' => 'closed']);
        $this->postJson("/api/v1/accounting/payables/bills/$first/post", [], ['X-Company-Id' => $context['company']->id])->assertUnprocessable()->assertJsonValidationErrors('posting_date');
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_po_conversion_allows_partial_received_billing_and_prevents_duplicate_quantity(): void
    {
        $context = $this->stage4AccountingContext();
        $order = $this->receivedOrder($context);
        $orderLine = $order->lines()->firstOrFail();
        $payload = $this->payload($context, [
            'purchase_order_id' => $order->id, 'supplier_invoice_number' => 'PO-BILL-1',
            'lines' => [[
                'purchase_order_line_id' => $orderLine->id, 'description' => 'PO service', 'procurement_type' => 'service',
                'quantity_milli' => 400, 'unit' => 'unit', 'unit_price' => 10000, 'discount' => 0,
                'tax_rate_bps' => 0, 'withholding_rate_bps' => 0, 'expense_account_id' => $context['accounts']['purchase_expense']->id,
            ]],
        ]);
        $this->postJson("/api/v1/purchases/orders/{$order->id}/convert-to-bill", $payload, $this->headers($context['company']->id, 'po-bill-one'))->assertCreated();
        $payload['supplier_invoice_number'] = 'PO-BILL-2';
        $payload['lines'][0]['quantity_milli'] = 700;
        $this->postJson("/api/v1/purchases/orders/{$order->id}/convert-to-bill", $payload, $this->headers($context['company']->id, 'po-bill-two'))->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity_milli');
        $payload['lines'][0]['quantity_milli'] = 600;
        $this->postJson("/api/v1/purchases/orders/{$order->id}/convert-to-bill", $payload, $this->headers($context['company']->id, 'po-bill-three'))->assertCreated();
        $this->assertDatabaseHas('purchase_order_lines', ['id' => $orderLine->id, 'billed_quantity_milli' => 1000]);
    }

    public function test_duplicate_supplier_reference_immutable_posted_bill_and_void_reversal(): void
    {
        $context = $this->stage4AccountingContext();
        $billId = $this->createBill($context, 'unique-reference');
        $this->postJson('/api/v1/accounting/payables/bills', $this->payload($context), $this->headers($context['company']->id, 'duplicate-reference'))->assertUnprocessable()->assertJsonValidationErrors('supplier_invoice_number');
        $this->postJson("/api/v1/accounting/payables/bills/$billId/post", [], ['X-Company-Id' => $context['company']->id])->assertOk();
        $this->patchJson("/api/v1/accounting/payables/bills/$billId", $this->payload($context), ['X-Company-Id' => $context['company']->id])->assertUnprocessable();
        $this->postJson("/api/v1/accounting/payables/bills/$billId/void", ['posting_date' => '2026-09-24', 'reason' => 'Supplier cancelled invoice'], ['X-Company-Id' => $context['company']->id])->assertOk()->assertJsonPath('accounting_status', 'void')->assertJsonPath('balance_due', 0);
        $this->assertDatabaseCount('journals', 2);
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $overrides */
    private function createBill(array $context, string $key, array $overrides = []): string
    {
        return (string) $this->postJson('/api/v1/accounting/payables/bills', $this->payload($context, $overrides), $this->headers($context['company']->id, $key))->assertCreated()->json('id');
    }

    /** @param array<string,mixed> $context */
    private function receivedOrder(array $context): PurchaseOrder
    {
        $orderPayload = ['supplier_id' => $context['supplier']->id, 'order_date' => '2026-09-22', 'currency' => 'PKR', 'lines' => [['description' => 'PO service', 'procurement_type' => 'service', 'quantity_milli' => 1000, 'unit' => 'unit', 'unit_price' => 10000, 'expense_account_id' => $context['accounts']['purchase_expense']->id]]];
        $id = $this->postJson('/api/v1/purchases/orders', $orderPayload, $this->headers($context['company']->id, 'bill-po'))->assertCreated()->json('id');
        $headers = ['X-Company-Id' => $context['company']->id];
        $this->postJson("/api/v1/purchases/orders/$id/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/purchases/orders/$id/approve", [], $headers)->assertOk();
        $order = PurchaseOrder::query()->with('lines')->findOrFail($id);
        $this->postJson("/api/v1/purchases/orders/$id/receipts", ['receipt_date' => '2026-09-23', 'lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'quantity_received_milli' => 1000]]], $this->headers($context['company']->id, 'bill-receipt'))->assertCreated();

        return $order->fresh('lines');
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $overrides @return array<string,mixed> */
    private function payload(array $context, array $overrides = []): array
    {
        return array_replace_recursive([
            'supplier_id' => $context['supplier']->id, 'supplier_invoice_number' => 'SUP-INV-1', 'bill_date' => '2026-09-22',
            'posting_date' => '2026-09-22', 'due_date' => '2026-09-30', 'currency' => 'PKR', 'lines' => [[
                'description' => 'Professional services', 'procurement_type' => 'service', 'quantity_milli' => 1000, 'unit' => 'unit',
                'unit_price' => 100000, 'discount' => 0, 'tax_rate_bps' => 1800, 'withholding_rate_bps' => 500,
                'expense_account_id' => $context['accounts']['purchase_expense']->id,
            ]],
        ], $overrides);
    }

    /** @return array<string,string> */
    private function headers(string $companyId, string $key): array
    {
        return ['X-Company-Id' => $companyId, 'Idempotency-Key' => $key];
    }
}
