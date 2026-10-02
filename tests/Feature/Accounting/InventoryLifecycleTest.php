<?php

namespace Tests\Feature\Accounting;

use App\Enums\PurchaseOrderStatus;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\InventoryConsumption;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\InventoryTransaction;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Journal;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\Warehouse;
use App\Services\Accounting\InvoicePostingService;
use App\Services\Accounting\PurchaseReceiptService;
use App\Services\Accounting\SupplierBillPostingService;
use App\Services\Inventory\InventoryReportingService;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class InventoryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifo_consumes_oldest_layers_preserves_remainders_and_prevents_negative_stock(): void
    {
        $context = $this->stage5AccountingContext();
        $service = app(InventoryService::class);
        $service->adjust($context['company']->id, $context['user'], $this->adjustment($context, 100000, 500), 'layer-1');
        $service->adjust($context['company']->id, $context['user'], $this->adjustment($context, 100000, 600), 'layer-2');
        $issue = $service->adjust($context['company']->id, $context['user'], [...$this->adjustment($context, 150000, null), 'direction' => 'negative'], 'issue-1');

        $out = $issue->movements->first();
        $this->assertSame(80000, $out->movement_value);
        $this->assertSame(2, InventoryConsumption::query()->where('outbound_movement_id', $out->id)->count());
        $layers = InventoryLayer::query()->where('item_id', $context['item']->id)->orderBy('received_date')->orderBy('id')->get();
        $this->assertSame(0, $layers[0]->remaining_quantity_milli);
        $this->assertSame(50000, $layers[1]->remaining_quantity_milli);
        $this->assertSame(30000, $layers[1]->remaining_value);
        $this->assertSame(50000, InventoryMovement::query()->where('item_id', $context['item']->id)->sum('quantity_in_milli') - InventoryMovement::query()->where('item_id', $context['item']->id)->sum('quantity_out_milli'));

        $this->expectException(ValidationException::class);
        $service->adjust($context['company']->id, $context['user'], [...$this->adjustment($context, 50001, null), 'direction' => 'negative'], 'over-issue');
    }

    public function test_adjustments_transfers_accounting_locks_and_idempotency_are_atomic(): void
    {
        $context = $this->stage5AccountingContext();
        $service = app(InventoryService::class);
        $positive = $service->adjust($context['company']->id, $context['user'], $this->adjustment($context, 10000, 2500), 'adjust-once');
        $retry = $service->adjust($context['company']->id, $context['user'], $this->adjustment($context, 10000, 2500), 'adjust-once');
        $this->assertSame($positive->id, $retry->id);
        $this->assertSame(1, InventoryTransaction::query()->where('idempotency_key', 'adjust-once')->count());
        $this->assertNotNull($positive->journal_id);
        $this->assertSame(2, $positive->journal->lines()->count());

        $destination = Warehouse::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        $transfer = $service->transfer($context['company']->id, $context['user'], [
            'item_id' => $context['item']->id, 'source_warehouse_id' => $context['warehouse']->id,
            'destination_warehouse_id' => $destination->id, 'quantity_milli' => 4000,
            'transaction_date' => '2026-09-15', 'reason' => 'Rebalance',
        ], 'transfer-once');
        $this->assertCount(2, $transfer->movements);
        $this->assertNull($transfer->journal_id);
        $this->assertSame(20000, $transfer->movements->sum('movement_value'));
        $this->assertSame(10000, (int) InventoryLayer::query()->where('warehouse_id', $destination->id)->sum('remaining_value'));

        try {
            $service->transfer($context['company']->id, $context['user'], [
                'item_id' => $context['item']->id, 'source_warehouse_id' => $context['warehouse']->id,
                'destination_warehouse_id' => $destination->id, 'quantity_milli' => 6001,
                'transaction_date' => '2026-09-15', 'reason' => 'Too much',
            ], 'over-transfer');
            $this->fail('Over-transfer should fail.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('inventory_transactions', ['idempotency_key' => 'over-transfer']);
        }

        AccountingPeriod::query()->where('company_id', $context['company']->id)->update(['status' => 'locked']);
        $this->expectException(ValidationException::class);
        $service->adjust($context['company']->id, $context['user'], $this->adjustment($context, 1000, 100), 'locked-adjustment');
    }

    public function test_exact_depletion_same_warehouse_rejection_and_locked_transfer_are_atomic(): void
    {
        $context = $this->stage5AccountingContext();
        $service = app(InventoryService::class);
        $service->adjust($context['company']->id, $context['user'], $this->adjustment($context, 5000, 700), 'exact-layer');

        $exact = $service->adjust($context['company']->id, $context['user'], [
            ...$this->adjustment($context, 5000, null),
            'direction' => 'negative',
        ], 'exact-issue');
        $this->assertSame(3500, $exact->movements->sum('movement_value'));
        $this->assertSame(0, (int) InventoryLayer::query()->where('item_id', $context['item']->id)->sum('remaining_quantity_milli'));
        $this->assertSame(0, (int) InventoryLayer::query()->where('item_id', $context['item']->id)->sum('remaining_value'));

        try {
            $service->transfer($context['company']->id, $context['user'], [
                'item_id' => $context['item']->id,
                'source_warehouse_id' => $context['warehouse']->id,
                'destination_warehouse_id' => $context['warehouse']->id,
                'quantity_milli' => 1000,
                'transaction_date' => '2026-09-15',
                'reason' => 'Invalid same warehouse transfer',
            ], 'same-warehouse');
            $this->fail('A same-warehouse transfer must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('inventory_transactions', ['idempotency_key' => 'same-warehouse']);
        }

        $service->adjust($context['company']->id, $context['user'], $this->adjustment($context, 2000, 700), 'locked-transfer-stock');
        $destination = Warehouse::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        AccountingPeriod::query()->where('company_id', $context['company']->id)->update(['status' => 'locked']);
        try {
            $service->transfer($context['company']->id, $context['user'], [
                'item_id' => $context['item']->id,
                'source_warehouse_id' => $context['warehouse']->id,
                'destination_warehouse_id' => $destination->id,
                'quantity_milli' => 1000,
                'transaction_date' => '2026-09-15',
                'reason' => 'Locked period transfer',
            ], 'locked-transfer');
            $this->fail('A transfer in a locked period must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('inventory_transactions', ['idempotency_key' => 'locked-transfer']);
            $this->assertSame(2000, (int) InventoryLayer::query()->where('item_id', $context['item']->id)->sum('remaining_quantity_milli'));
        }
    }

    public function test_failed_inventory_journal_rolls_back_movement_layer_and_transaction(): void
    {
        $context = $this->stage5AccountingContext();
        $context['accounts']['inventory_adjustment']->update(['is_active' => false]);

        try {
            app(InventoryService::class)->adjust(
                $context['company']->id,
                $context['user'],
                $this->adjustment($context, 1000, 1000),
                'journal-failure',
            );
            $this->fail('An invalid inventory journal must fail.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('inventory_transactions', ['idempotency_key' => 'journal-failure']);
            $this->assertSame(0, InventoryMovement::query()->where('company_id', $context['company']->id)->count());
            $this->assertSame(0, InventoryLayer::query()->where('company_id', $context['company']->id)->count());
        }
    }

    public function test_purchase_receipt_creates_inventory_once_and_ignores_service_lines(): void
    {
        $context = $this->stage5AccountingContext();
        $order = PurchaseOrder::factory()->for($context['company'])->for($context['supplier'])->create(['status' => PurchaseOrderStatus::Approved, 'created_by' => $context['user']->id]);
        $goods = PurchaseOrderLine::factory()->for($order)->create([
            'position' => 1, 'item_id' => $context['item']->id, 'item_name' => $context['item']->name,
            'procurement_type' => 'goods', 'quantity_milli' => 10000, 'unit_price' => 1000,
            'subtotal' => 10000, 'taxable_amount' => 9000, 'discount' => 1000, 'tax_amount' => 0, 'total' => 9000,
            'expense_account_id' => $context['accounts']['inventory_asset']->id,
        ]);
        $serviceLine = PurchaseOrderLine::factory()->for($order)->create(['position' => 2, 'quantity_milli' => 1000, 'received_quantity_milli' => 0]);
        $data = ['receipt_date' => '2026-09-15', 'warehouse_id' => $context['warehouse']->id, 'lines' => [
            ['purchase_order_line_id' => $goods->id, 'quantity_received_milli' => 10000],
            ['purchase_order_line_id' => $serviceLine->id, 'quantity_received_milli' => 1000],
        ]];
        $receipt = app(PurchaseReceiptService::class)->receive($context['company']->id, $context['user'], $order, $data, 'receipt-once');
        $retry = app(PurchaseReceiptService::class)->receive($context['company']->id, $context['user'], $order, $data, 'receipt-once');

        $this->assertSame($receipt->id, $retry->id);
        $this->assertSame($context['warehouse']->id, $receipt->warehouse_id);
        $this->assertSame(1, InventoryMovement::query()->where('source_type', 'purchase_receipt')->where('source_id', $receipt->id)->count());
        $this->assertSame(10000, (int) InventoryLayer::query()->where('item_id', $context['item']->id)->sum('remaining_quantity_milli'));
        $this->assertSame(9000, (int) InventoryLayer::query()->where('item_id', $context['item']->id)->sum('remaining_value'));
        $this->getJson("/api/v1/purchases/receipts/{$receipt->id}", ['X-Company-Id' => $context['company']->id])
            ->assertOk()
            ->assertJsonPath('warehouse_id', $context['warehouse']->id)
            ->assertJsonPath('inventory_status', 'stocked')
            ->assertJsonPath('inventory_transaction_id', InventoryTransaction::query()->where('source_id', $receipt->id)->value('id'));
    }

    public function test_invoice_issue_posts_cogs_and_customer_return_restores_historical_cost_once(): void
    {
        $context = $this->stage5AccountingContext();
        $inventory = app(InventoryService::class);
        $inventory->adjust($context['company']->id, $context['user'], $this->adjustment($context, 10000, 500), 'sale-stock');
        $invoice = Invoice::factory()->for($context['company'])->for($context['customer'])->create(['created_by' => $context['user']->id, 'subtotal' => 10000, 'taxable_amount' => 10000, 'sales_tax' => 0, 'total' => 10000, 'balance_due' => 10000]);
        $line = InvoiceLine::factory()->for($invoice)->create(['item_id' => $context['item']->id, 'item_name' => $context['item']->name, 'quantity_milli' => 6000, 'unit_price' => 2000, 'subtotal' => 12000, 'discount' => 2000, 'taxable_amount' => 10000, 'tax_amount' => 0, 'total' => 10000]);
        $posted = app(InvoicePostingService::class)->post($context['company']->id, $context['user'], $invoice);
        $postedRetry = app(InvoicePostingService::class)->post($context['company']->id, $context['user'], $invoice);
        $issue = InventoryTransaction::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->firstOrFail();
        $this->assertSame($posted->id, $postedRetry->id);
        $this->assertSame(1, InventoryTransaction::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->count());
        $this->assertNotNull($posted->journal_id);
        $this->assertNotNull($issue->journal_id);
        $this->assertSame(3000, (int) $issue->movements()->sum('movement_value'));
        $this->assertSame(2, Journal::query()->whereIn('id', [$posted->journal_id, $issue->journal_id])->count());

        $returnData = ['warehouse_id' => $context['warehouse']->id, 'transaction_date' => '2026-09-16', 'reason' => 'Customer return', 'lines' => [['source_line_id' => $line->id, 'quantity_milli' => 2000]]];
        $return = $inventory->customerReturn($context['company']->id, $context['user'], $invoice, $returnData, 'customer-return-once');
        $retry = $inventory->customerReturn($context['company']->id, $context['user'], $invoice, $returnData, 'customer-return-once');
        $this->assertSame($return->id, $retry->id);
        $this->assertSame(1000, $return->movements->sum('movement_value'));
        $this->assertNotNull($return->journal_id);
        $this->assertSame(6000, (int) InventoryLayer::query()->where('item_id', $context['item']->id)->sum('remaining_quantity_milli'));

        $this->expectException(ValidationException::class);
        $inventory->customerReturn($context['company']->id, $context['user'], $invoice, [...$returnData, 'lines' => [['source_line_id' => $line->id, 'quantity_milli' => 5000]]], 'customer-return-over');
    }

    public function test_same_idempotency_key_with_different_payload_is_rejected(): void
    {
        $context = $this->stage5AccountingContext();
        $service = app(InventoryService::class);
        $service->adjust($context['company']->id, $context['user'], $this->adjustment($context, 1000, 100), 'same-key');

        $this->expectException(ConflictHttpException::class);
        $service->adjust($context['company']->id, $context['user'], $this->adjustment($context, 2000, 100), 'same-key');
    }

    public function test_supplier_return_and_explicit_cost_adjustment_are_journal_backed_and_idempotent(): void
    {
        $context = $this->stage5AccountingContext();
        $service = app(InventoryService::class);
        $service->adjust($context['company']->id, $context['user'], $this->adjustment($context, 10000, 1000), 'supplier-stock');
        $layer = InventoryLayer::query()->where('item_id', $context['item']->id)->firstOrFail();
        $adjustment = $service->costAdjustment($context['company']->id, $context['user'], [
            'layer_id' => $layer->id, 'amount' => 500, 'transaction_date' => '2026-09-16',
            'reason' => 'Supplier bill landed cost difference',
        ], 'cost-adjustment');
        $this->assertNotNull($adjustment->journal_id);
        $this->assertSame(10500, $layer->fresh()->remaining_value);

        $order = PurchaseOrder::factory()->for($context['company'])->for($context['supplier'])->create(['status' => PurchaseOrderStatus::Approved, 'created_by' => $context['user']->id]);
        $orderLine = PurchaseOrderLine::factory()->for($order)->create(['item_id' => $context['item']->id, 'procurement_type' => 'goods', 'quantity_milli' => 10000, 'received_quantity_milli' => 10000]);
        $receipt = PurchaseReceipt::factory()->for($order, 'purchaseOrder')->for($context['supplier'])->create(['company_id' => $context['company']->id, 'warehouse_id' => $context['warehouse']->id, 'received_by' => $context['user']->id]);
        $receiptLine = PurchaseReceiptLine::factory()->for($receipt, 'receipt')->for($orderLine, 'purchaseOrderLine')->create(['quantity_received_milli' => 10000, 'ordered_quantity_milli' => 10000, 'remaining_quantity_milli' => 0]);
        $data = ['warehouse_id' => $context['warehouse']->id, 'transaction_date' => '2026-09-17', 'reason' => 'Damaged goods', 'lines' => [['source_line_id' => $receiptLine->id, 'quantity_milli' => 2000]]];
        $return = $service->supplierReturn($context['company']->id, $context['user'], $receipt, $data, 'supplier-return');
        $retry = $service->supplierReturn($context['company']->id, $context['user'], $receipt, $data, 'supplier-return');

        $this->assertSame($return->id, $retry->id);
        $this->assertNotNull($return->journal_id);
        $this->assertSame(2000, $return->movements->sum('quantity_out_milli'));
        $this->assertSame(8000, (int) InventoryLayer::query()->where('item_id', $context['item']->id)->sum('remaining_quantity_milli'));
        $this->assertSame(1, InventoryTransaction::query()->where('idempotency_key', 'supplier-return')->count());

        try {
            $service->supplierReturn($context['company']->id, $context['user'], $receipt, [
                ...$data,
                'lines' => [['source_line_id' => $receiptLine->id, 'quantity_milli' => 9000]],
            ], 'supplier-return-over');
            $this->fail('Supplier return quantity beyond the receipt must fail.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('inventory_transactions', ['idempotency_key' => 'supplier-return-over']);
        }
    }

    public function test_inventory_api_adjustment_and_transfer_actions_are_audited(): void
    {
        $context = $this->stage5AccountingContext();
        $headers = ['X-Company-Id' => $context['company']->id, 'Accept' => 'application/json'];
        $this->postJson('/api/v1/inventory/adjustments', $this->adjustment($context, 3000, 500), [
            ...$headers,
            'Idempotency-Key' => 'audited-adjustment',
        ])->assertCreated();

        $destination = Warehouse::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        $this->postJson('/api/v1/inventory/transfers', [
            'item_id' => $context['item']->id,
            'source_warehouse_id' => $context['warehouse']->id,
            'destination_warehouse_id' => $destination->id,
            'quantity_milli' => 1000,
            'transaction_date' => '2026-09-15',
            'reason' => 'Audited transfer',
        ], [
            ...$headers,
            'Idempotency-Key' => 'audited-transfer',
        ])->assertCreated();

        $this->assertSame(1, AuditLog::query()->where('company_id', $context['company']->id)->where('module', 'inventory')->where('action', 'adjust')->count());
        $this->assertSame(1, AuditLog::query()->where('company_id', $context['company']->id)->where('module', 'inventory')->where('action', 'transfer')->count());
    }

    public function test_supplier_bill_price_variance_keeps_fifo_and_inventory_gl_reconciled(): void
    {
        $context = $this->stage5AccountingContext();
        $order = PurchaseOrder::factory()->for($context['company'])->for($context['supplier'])->create(['status' => PurchaseOrderStatus::Approved, 'created_by' => $context['user']->id]);
        $orderLine = PurchaseOrderLine::factory()->for($order)->create([
            'item_id' => $context['item']->id, 'procurement_type' => 'goods', 'quantity_milli' => 10000,
            'received_quantity_milli' => 0, 'billed_quantity_milli' => 0, 'unit_price' => 1000,
            'subtotal' => 10000, 'taxable_amount' => 10000, 'tax_amount' => 0, 'total' => 10000,
            'expense_account_id' => $context['accounts']['inventory_asset']->id,
        ]);
        app(PurchaseReceiptService::class)->receive($context['company']->id, $context['user'], $order, [
            'receipt_date' => '2026-09-15', 'warehouse_id' => $context['warehouse']->id,
            'lines' => [['purchase_order_line_id' => $orderLine->id, 'quantity_received_milli' => 10000]],
        ], 'bill-receipt');
        $orderLine->refresh()->update(['billed_quantity_milli' => 10000]);
        $bill = SupplierBill::factory()->for($context['company'])->for($context['supplier'])->create([
            'purchase_order_id' => $order->id, 'subtotal' => 12000, 'taxable_amount' => 12000,
            'purchase_tax' => 0, 'withholding_tax' => 0, 'gross_total' => 12000, 'total' => 12000,
            'balance_due' => 12000, 'created_by' => $context['user']->id,
        ]);
        SupplierBillLine::factory()->for($bill, 'bill')->create([
            'purchase_order_line_id' => $orderLine->id, 'item_id' => $context['item']->id,
            'procurement_type' => 'goods', 'quantity_milli' => 10000, 'unit_price' => 1200,
            'subtotal' => 12000, 'taxable_amount' => 12000, 'tax_rate_bps' => 0, 'tax_amount' => 0,
            'withholding_rate_bps' => 0, 'withholding_amount' => 0, 'total' => 12000,
            'expense_account_id' => $context['accounts']['inventory_asset']->id,
        ]);

        $posted = app(SupplierBillPostingService::class)->post($context['company']->id, $context['user'], $bill);
        $lines = $posted->journal->lines;
        $this->assertSame(10000, $lines->where('account_id', $context['accounts']['inventory_asset']->id)->sum('debit'));
        $this->assertSame(2000, $lines->where('account_id', $context['accounts']['inventory_adjustment']->id)->sum('debit'));
        $this->assertSame(12000, $lines->where('account_id', $context['accounts']['accounts_payable']->id)->sum('credit'));
        $this->assertSame(10000, (int) InventoryLayer::query()->where('item_id', $context['item']->id)->sum('remaining_value'));
        $this->assertSame(0, app(InventoryReportingService::class)->reconciliation($context['company']->id, [])['difference']);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function adjustment(array $context, int $quantity, ?int $unitCost): array
    {
        return ['item_id' => $context['item']->id, 'warehouse_id' => $context['warehouse']->id, 'quantity_milli' => $quantity, 'unit_cost' => $unitCost, 'direction' => 'positive', 'transaction_date' => '2026-09-15', 'reason' => 'Test adjustment'];
    }
}
