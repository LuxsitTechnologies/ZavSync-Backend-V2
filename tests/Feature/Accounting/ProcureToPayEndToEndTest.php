<?php

namespace Tests\Feature\Accounting;

use App\Models\JournalLine;
use App\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcureToPayEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_supplier_to_settlement_flow_reconciles_procurement_gl_and_ap(): void
    {
        $context = $this->stage4AccountingContext();
        $companyId = $context['company']->id;
        $headers = ['X-Company-Id' => $companyId];
        $orderPayload = [
            'supplier_id' => $context['supplier']->id, 'order_date' => '2026-09-20', 'currency' => 'PKR',
            'lines' => [['description' => 'End-to-end service', 'procurement_type' => 'service', 'quantity_milli' => 2500, 'unit' => 'hour', 'unit_price' => 20000, 'discount' => 0, 'tax_rate_bps' => 1800, 'expense_account_id' => $context['accounts']['purchase_expense']->id]],
        ];
        $orderId = $this->postJson('/api/v1/purchases/orders', $orderPayload, [...$headers, 'Idempotency-Key' => 'e2e-order'])->assertCreated()->json('id');
        $this->postJson("/api/v1/purchases/orders/$orderId/submit", [], $headers)->assertOk()->assertJsonPath('status', 'pending');
        $this->postJson("/api/v1/purchases/orders/$orderId/approve", [], $headers)->assertOk()->assertJsonPath('status', 'approved');
        $order = PurchaseOrder::query()->with('lines')->findOrFail($orderId);
        $line = $order->lines->firstOrFail();

        $this->postJson("/api/v1/purchases/orders/$orderId/receipts", ['receipt_date' => '2026-09-21', 'lines' => [['purchase_order_line_id' => $line->id, 'quantity_received_milli' => 2500]]], [...$headers, 'Idempotency-Key' => 'e2e-receipt'])->assertCreated()->assertJsonPath('status', 'complete');
        $this->assertDatabaseCount('journals', 0);

        $billPayload = [
            'supplier_id' => $context['supplier']->id, 'purchase_order_id' => $orderId, 'supplier_invoice_number' => 'E2E-SUP-1',
            'bill_date' => '2026-09-21', 'posting_date' => '2026-09-21', 'due_date' => '2026-09-30', 'currency' => 'PKR',
            'lines' => [['purchase_order_line_id' => $line->id, 'description' => 'End-to-end service', 'procurement_type' => 'service', 'quantity_milli' => 2500, 'unit' => 'hour', 'unit_price' => 20000, 'discount' => 0, 'tax_rate_bps' => 1800, 'withholding_rate_bps' => 500, 'expense_account_id' => $context['accounts']['purchase_expense']->id]],
        ];
        $billId = $this->postJson("/api/v1/purchases/orders/$orderId/convert-to-bill", $billPayload, [...$headers, 'Idempotency-Key' => 'e2e-bill'])->assertCreated()->assertJsonPath('accounting_status', 'draft')->json('id');
        $this->assertDatabaseCount('journals', 0);
        $this->postJson("/api/v1/accounting/payables/bills/$billId/post", [], $headers)->assertOk()->assertJsonPath('total', 56500)->assertJsonPath('balance_due', 56500);
        $this->getJson('/api/v1/accounting/payables/aging?as_of=2026-09-22', $headers)->assertOk()->assertJsonPath('0.current', 56500);

        $payment = ['payment_date' => '2026-09-22', 'posting_date' => '2026-09-22', 'method' => 'bank_transfer', 'bank_account_id' => $context['accounts']['bank']->id];
        $this->postJson("/api/v1/accounting/payables/bills/$billId/payments", [...$payment, 'amount' => 20000], [...$headers, 'Idempotency-Key' => 'e2e-payment-1'])->assertOk()->assertJsonPath('accounting_status', 'partial')->assertJsonPath('balance_due', 36500);
        $this->postJson("/api/v1/accounting/payables/bills/$billId/payments", [...$payment, 'amount' => 36500], [...$headers, 'Idempotency-Key' => 'e2e-payment-2'])->assertOk()->assertJsonPath('accounting_status', 'paid')->assertJsonPath('balance_due', 0);

        $this->assertDatabaseCount('journals', 3);
        $this->assertSame(115500, (int) JournalLine::query()->sum('debit'));
        $this->assertSame(115500, (int) JournalLine::query()->sum('credit'));
        $this->getJson("/api/v1/accounting/payables/suppliers/{$context['supplier']->id}/statement?from=2026-09-01&to=2026-09-30", $headers)->assertOk()->assertJsonPath('closing_balance', 0)->assertJsonCount(3, 'lines');
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $billId, 'action' => 'post', 'module' => 'accounts_payable']);
    }
}
