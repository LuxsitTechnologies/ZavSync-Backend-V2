<?php

namespace Tests\Feature\Accounting;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_and_final_receipts_track_remaining_quantities_without_gl(): void
    {
        $context = $this->stage4AccountingContext();
        $order = $this->approvedOrder($context);
        $lineId = $order->lines()->firstOrFail()->id;

        $first = $this->postJson("/api/v1/purchases/orders/{$order->id}/receipts", ['receipt_date' => '2026-09-23', 'lines' => [['purchase_order_line_id' => $lineId, 'quantity_received_milli' => 400]]], $this->headers($context['company']->id, 'receipt-one'));
        $first->assertCreated()->assertJsonPath('status', 'partial')->assertJsonPath('lines.0.remaining_quantity_milli', 600);
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'partially_received']);

        $this->postJson("/api/v1/purchases/orders/{$order->id}/receipts", ['receipt_date' => '2026-09-24', 'lines' => [['purchase_order_line_id' => $lineId, 'quantity_received_milli' => 600]]], $this->headers($context['company']->id, 'receipt-two'))
            ->assertCreated()->assertJsonPath('status', 'complete')->assertJsonPath('lines.0.previously_received_quantity_milli', 400)->assertJsonPath('lines.0.remaining_quantity_milli', 0);
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'received']);
        $this->assertDatabaseCount('journals', 0);
        $this->postJson("/api/v1/purchases/orders/{$order->id}/cancel", ['reason' => 'Too late'], ['X-Company-Id' => $context['company']->id])->assertUnprocessable();
    }

    public function test_over_receipt_wrong_state_cross_company_and_retry_are_rejected_safely(): void
    {
        $context = $this->stage4AccountingContext();
        $order = $this->approvedOrder($context);
        $lineId = $order->lines()->firstOrFail()->id;
        $payload = ['receipt_date' => '2026-09-23', 'lines' => [['purchase_order_line_id' => $lineId, 'quantity_received_milli' => 1001]]];
        $this->postJson("/api/v1/purchases/orders/{$order->id}/receipts", $payload, $this->headers($context['company']->id, 'too-much'))->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity_received_milli');

        $valid = ['receipt_date' => '2026-09-23', 'lines' => [['purchase_order_line_id' => $lineId, 'quantity_received_milli' => 250]]];
        $headers = $this->headers($context['company']->id, 'receipt-repeat');
        $first = $this->postJson("/api/v1/purchases/orders/{$order->id}/receipts", $valid, $headers)->assertCreated();
        $this->postJson("/api/v1/purchases/orders/{$order->id}/receipts", $valid, $headers)->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->assertDatabaseCount('purchase_receipts', 1);

        $other = $this->stage4AccountingContext();
        $this->postJson("/api/v1/purchases/orders/{$order->id}/receipts", $valid, $this->headers($other['company']->id, 'cross-company'))->assertUnprocessable();
    }

    /** @param array<string,mixed> $context */
    private function approvedOrder(array $context): PurchaseOrder
    {
        $payload = ['supplier_id' => $context['supplier']->id, 'order_date' => '2026-09-22', 'currency' => 'PKR', 'lines' => [['description' => 'Confirmed services', 'procurement_type' => 'service', 'quantity_milli' => 1000, 'unit' => 'unit', 'unit_price' => 10000, 'expense_account_id' => $context['accounts']['purchase_expense']->id]]];
        $id = $this->postJson('/api/v1/purchases/orders', $payload, $this->headers($context['company']->id, 'receipt-order'))->assertCreated()->json('id');
        $headers = ['X-Company-Id' => $context['company']->id];
        $this->postJson("/api/v1/purchases/orders/$id/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/purchases/orders/$id/approve", [], $headers)->assertOk();

        return PurchaseOrder::query()->with('lines')->findOrFail($id);
    }

    /** @return array<string,string> */
    private function headers(string $companyId, string $key): array
    {
        return ['X-Company-Id' => $companyId, 'Idempotency-Key' => $key];
    }
}
