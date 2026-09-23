<?php

namespace App\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\Company;
use App\Models\InventoryConsumption;
use App\Models\InventoryItem;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\InventoryTransaction;
use App\Models\Invoice;
use App\Models\PurchaseReceipt;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class InventoryService
{
    public function __construct(
        private readonly IntegerAllocationService $allocation,
        private readonly JournalPostingService $journalPostingService,
    ) {}

    public function receivePurchaseReceipt(string $companyId, User $user, PurchaseReceipt $receipt, Warehouse $warehouse): InventoryTransaction
    {
        $receipt->loadMissing('lines.purchaseOrderLine');
        $payload = ['receipt_id' => $receipt->id, 'warehouse_id' => $warehouse->id, 'lines' => $receipt->lines->map(fn ($line) => ['id' => $line->id, 'quantity_milli' => $line->quantity_received_milli])->all()];

        return $this->execute($companyId, $user, InventoryMovementType::PurchaseReceipt, $receipt->receipt_date->format('Y-m-d'), "purchase-receipt:{$receipt->id}", $payload, [
            'source_warehouse_id' => $warehouse->id, 'source_type' => 'purchase_receipt', 'source_id' => $receipt->id,
            'reference' => $receipt->number,
        ], function (InventoryTransaction $transaction) use ($companyId, $user, $receipt, $warehouse): void {
            foreach ($receipt->lines as $receiptLine) {
                $orderLine = $receiptLine->purchaseOrderLine;
                if ($orderLine->procurement_type !== 'goods' || $orderLine->item_id === null) {
                    continue;
                }
                $item = $this->trackedItem($companyId, $orderLine->item_id);
                if ($item === null) {
                    continue;
                }
                $previousValue = $this->allocation->proportional($orderLine->taxable_amount, $receiptLine->previously_received_quantity_milli, $orderLine->quantity_milli);
                $cumulativeValue = $this->allocation->proportional($orderLine->taxable_amount, $receiptLine->previously_received_quantity_milli + $receiptLine->quantity_received_milli, $orderLine->quantity_milli);
                $value = $cumulativeValue - $previousValue;
                $movement = $this->inboundMovement($transaction, $item, $warehouse, $receiptLine->quantity_received_milli, $value, $receiptLine->id, $user);
                $this->createLayer($movement, $receiptLine->quantity_received_milli, $value);
            }
        });
    }

    public function issueInvoice(string $companyId, User $user, Invoice $invoice): ?InventoryTransaction
    {
        $invoice->loadMissing('lines');
        $itemIds = $invoice->lines->pluck('item_id')->filter()->unique()->values();
        $items = InventoryItem::query()->where('company_id', $companyId)->whereIn('id', $itemIds)->get()->keyBy('id');
        $trackedLines = $invoice->lines->filter(fn ($line) => $line->item_id !== null && $items->get($line->item_id)?->isTracked());
        if ($trackedLines->isEmpty()) {
            return null;
        }
        $warehouse = Warehouse::query()->where('company_id', $companyId)->where('is_active', true)->where('is_default', true)->first();
        if ($warehouse === null) {
            throw ValidationException::withMessages(['warehouse' => 'Configure an active default warehouse before posting an invoice with inventory items.']);
        }
        $payload = ['invoice_id' => $invoice->id, 'warehouse_id' => $warehouse->id, 'lines' => $trackedLines->map(fn ($line) => ['id' => $line->id, 'quantity_milli' => $line->quantity_milli])->values()->all()];

        return $this->execute($companyId, $user, InventoryMovementType::SaleIssue, $invoice->invoice_date->format('Y-m-d'), "invoice-stock:{$invoice->id}", $payload, [
            'source_warehouse_id' => $warehouse->id, 'source_type' => 'invoice', 'source_id' => $invoice->id, 'reference' => $invoice->invoice_number,
        ], function (InventoryTransaction $transaction) use ($trackedLines, $items, $warehouse, $user): void {
            $costs = [];
            foreach ($trackedLines as $line) {
                $item = $items->get($line->item_id);
                $movement = $this->outboundMovement($transaction, $item, $warehouse, $line->quantity_milli, $line->id, $user);
                $value = $this->consumeFifo($movement, $line->quantity_milli);
                $movement->update(['unit_cost' => $this->allocation->unitCost($value, $line->quantity_milli), 'movement_value' => $value, 'value_delta' => -$value]);
                $costs[] = ['item' => $item, 'value' => $value];
            }
            $this->postJournal($transaction, $user, $costs, 'cogs');
        });
    }

    /** @param array<string,mixed> $data */
    public function transfer(string $companyId, User $user, array $data, string $idempotencyKey): InventoryTransaction
    {
        $source = $this->warehouse($companyId, $data['source_warehouse_id']);
        $destination = $this->warehouse($companyId, $data['destination_warehouse_id']);
        if ($source->is($destination)) {
            throw ValidationException::withMessages(['destination_warehouse_id' => 'Destination warehouse must differ from source warehouse.']);
        }
        $item = $this->requireTrackedItem($companyId, $data['item_id']);

        return $this->execute($companyId, $user, InventoryMovementType::TransferOut, $data['transaction_date'], $idempotencyKey, $data, [
            'source_warehouse_id' => $source->id, 'destination_warehouse_id' => $destination->id,
            'reference' => $data['reference'] ?? null, 'reason' => $data['reason'], 'notes' => $data['notes'] ?? null,
        ], function (InventoryTransaction $transaction) use ($item, $source, $destination, $data, $user): void {
            $out = $this->outboundMovement($transaction, $item, $source, $data['quantity_milli'], null, $user, InventoryMovementType::TransferOut);
            $value = $this->consumeFifo($out, $data['quantity_milli']);
            $out->update(['unit_cost' => $this->allocation->unitCost($value, $data['quantity_milli']), 'movement_value' => $value, 'value_delta' => -$value]);
            $in = $this->inboundMovement($transaction, $item, $destination, $data['quantity_milli'], $value, null, $user, InventoryMovementType::TransferIn, $out);
            foreach ($out->consumptions()->get() as $consumption) {
                $this->createLayer($in, $consumption->quantity_milli, $consumption->value);
            }
        });
    }

    /** @param array<string,mixed> $data */
    public function adjust(string $companyId, User $user, array $data, string $idempotencyKey): InventoryTransaction
    {
        $warehouse = $this->warehouse($companyId, $data['warehouse_id']);
        $item = $this->requireTrackedItem($companyId, $data['item_id']);
        $type = $data['direction'] === 'positive' ? InventoryMovementType::PositiveAdjustment : InventoryMovementType::NegativeAdjustment;
        if ($type === InventoryMovementType::PositiveAdjustment && ! isset($data['unit_cost'])) {
            throw ValidationException::withMessages(['unit_cost' => 'A deterministic unit cost is required for a positive adjustment.']);
        }

        return $this->execute($companyId, $user, $type, $data['transaction_date'], $idempotencyKey, $data, [
            'source_warehouse_id' => $warehouse->id, 'reference' => $data['reference'] ?? null,
            'reason' => $data['reason'], 'notes' => $data['notes'] ?? null,
        ], function (InventoryTransaction $transaction) use ($type, $item, $warehouse, $data, $user): void {
            if ($type === InventoryMovementType::PositiveAdjustment) {
                $value = $this->allocation->valueFromUnitCost((int) $data['unit_cost'], $data['quantity_milli']);
                $movement = $this->inboundMovement($transaction, $item, $warehouse, $data['quantity_milli'], $value, null, $user, $type);
                $this->createLayer($movement, $data['quantity_milli'], $value);
                $this->postJournal($transaction, $user, [['item' => $item, 'value' => $value]], 'positive_adjustment');

                return;
            }
            $movement = $this->outboundMovement($transaction, $item, $warehouse, $data['quantity_milli'], null, $user, $type);
            $value = $this->consumeFifo($movement, $data['quantity_milli']);
            $movement->update(['unit_cost' => $this->allocation->unitCost($value, $data['quantity_milli']), 'movement_value' => $value, 'value_delta' => -$value]);
            $this->postJournal($transaction, $user, [['item' => $item, 'value' => $value]], 'negative_adjustment');
        });
    }

    /** @param array<string,mixed> $data */
    public function customerReturn(string $companyId, User $user, Invoice $invoice, array $data, string $idempotencyKey): InventoryTransaction
    {
        $warehouse = $this->warehouse($companyId, $data['warehouse_id']);
        $invoice = Invoice::query()->where('company_id', $companyId)->with('lines')->findOrFail($invoice->id);

        return $this->execute($companyId, $user, InventoryMovementType::CustomerReturn, $data['transaction_date'], $idempotencyKey, ['invoice_id' => $invoice->id, ...$data], [
            'source_warehouse_id' => $warehouse->id, 'source_type' => 'invoice', 'source_id' => $invoice->id,
            'reference' => $data['reference'] ?? $invoice->invoice_number, 'reason' => $data['reason'], 'notes' => $data['notes'] ?? null,
        ], function (InventoryTransaction $transaction) use ($companyId, $invoice, $warehouse, $data, $user): void {
            $costs = [];
            foreach ($data['lines'] as $index => $input) {
                $line = $invoice->lines->firstWhere('id', $input['source_line_id']);
                if ($line === null || $line->item_id === null) {
                    throw ValidationException::withMessages(["lines.$index.source_line_id" => 'The return line must belong to the selected invoice.']);
                }
                $item = $this->requireTrackedItem($companyId, $line->item_id);
                $sale = InventoryMovement::query()->where('company_id', $companyId)->where('type', InventoryMovementType::SaleIssue)->where('source_type', 'invoice')->where('source_id', $invoice->id)->where('source_line_id', $line->id)->with('consumptions')->first();
                if ($sale === null) {
                    throw ValidationException::withMessages(["lines.$index.source_line_id" => 'No posted stock issue exists for this invoice line.']);
                }
                $alreadyReturned = InventoryMovement::query()->where('company_id', $companyId)->where('type', InventoryMovementType::CustomerReturn)->where('source_type', 'invoice')->where('source_id', $invoice->id)->where('source_line_id', $line->id)->sum('quantity_in_milli');
                if ($alreadyReturned + $input['quantity_milli'] > $sale->quantity_out_milli) {
                    throw ValidationException::withMessages(["lines.$index.quantity_milli" => 'Return quantity exceeds the quantity issued for this invoice line.']);
                }
                $value = $this->historicalReturnValue($sale->consumptions, $alreadyReturned, $input['quantity_milli']);
                $movement = $this->inboundMovement($transaction, $item, $warehouse, $input['quantity_milli'], $value, $line->id, $user, InventoryMovementType::CustomerReturn, $sale);
                $this->createLayer($movement, $input['quantity_milli'], $value);
                $costs[] = ['item' => $item, 'value' => $value];
            }
            $this->postJournal($transaction, $user, $costs, 'customer_return');
        });
    }

    /** @param array<string,mixed> $data */
    public function supplierReturn(string $companyId, User $user, PurchaseReceipt $receipt, array $data, string $idempotencyKey): InventoryTransaction
    {
        $warehouse = $this->warehouse($companyId, $data['warehouse_id']);
        $receipt = PurchaseReceipt::query()->where('company_id', $companyId)->with('lines.purchaseOrderLine')->findOrFail($receipt->id);

        return $this->execute($companyId, $user, InventoryMovementType::SupplierReturn, $data['transaction_date'], $idempotencyKey, ['purchase_receipt_id' => $receipt->id, ...$data], [
            'source_warehouse_id' => $warehouse->id, 'source_type' => 'purchase_receipt', 'source_id' => $receipt->id,
            'reference' => $data['reference'] ?? $receipt->number, 'reason' => $data['reason'], 'notes' => $data['notes'] ?? null,
        ], function (InventoryTransaction $transaction) use ($companyId, $receipt, $warehouse, $data, $user): void {
            $costs = [];
            foreach ($data['lines'] as $index => $input) {
                $receiptLine = $receipt->lines->firstWhere('id', $input['source_line_id']);
                if ($receiptLine === null || $receiptLine->purchaseOrderLine->item_id === null) {
                    throw ValidationException::withMessages(["lines.$index.source_line_id" => 'The return line must belong to the selected purchase receipt.']);
                }
                $returned = InventoryMovement::query()->where('company_id', $companyId)->where('type', InventoryMovementType::SupplierReturn)->where('source_type', 'purchase_receipt')->where('source_id', $receipt->id)->where('source_line_id', $receiptLine->id)->sum('quantity_out_milli');
                if ($returned + $input['quantity_milli'] > $receiptLine->quantity_received_milli) {
                    throw ValidationException::withMessages(["lines.$index.quantity_milli" => 'Return quantity exceeds the quantity received on this receipt line.']);
                }
                $item = $this->requireTrackedItem($companyId, $receiptLine->purchaseOrderLine->item_id);
                $movement = $this->outboundMovement($transaction, $item, $warehouse, $input['quantity_milli'], $receiptLine->id, $user, InventoryMovementType::SupplierReturn);
                $value = $this->consumeFifo($movement, $input['quantity_milli']);
                $movement->update(['unit_cost' => $this->allocation->unitCost($value, $input['quantity_milli']), 'movement_value' => $value, 'value_delta' => -$value]);
                $costs[] = ['item' => $item, 'value' => $value];
            }
            $this->postJournal($transaction, $user, $costs, 'supplier_return');
        });
    }

    /** @param array<string,mixed> $data */
    public function costAdjustment(string $companyId, User $user, array $data, string $idempotencyKey): InventoryTransaction
    {
        $layer = InventoryLayer::query()->where('company_id', $companyId)->findOrFail($data['layer_id']);
        $item = $this->requireTrackedItem($companyId, $layer->item_id);
        $amount = (int) $data['amount'];

        return $this->execute($companyId, $user, InventoryMovementType::CostAdjustment, $data['transaction_date'], $idempotencyKey, $data, [
            'source_warehouse_id' => $layer->warehouse_id, 'source_type' => 'inventory_layer', 'source_id' => $layer->id,
            'reference' => $data['reference'] ?? null, 'reason' => $data['reason'], 'notes' => $data['notes'] ?? null,
        ], function (InventoryTransaction $transaction) use ($layer, $item, $amount, $user): void {
            $locked = InventoryLayer::query()->whereKey($layer->id)->lockForUpdate()->firstOrFail();
            if ($locked->remaining_quantity_milli <= 0) {
                throw ValidationException::withMessages(['layer_id' => 'Only an open FIFO layer can be cost adjusted.']);
            }
            if ($locked->remaining_value + $amount < 0 || $locked->original_value + $amount < 0) {
                throw ValidationException::withMessages(['amount' => 'A cost adjustment cannot reduce a FIFO layer below zero value.']);
            }
            $locked->update([
                'original_value' => $locked->original_value + $amount, 'remaining_value' => $locked->remaining_value + $amount,
                'unit_cost' => $this->allocation->unitCost($locked->remaining_value + $amount, $locked->remaining_quantity_milli),
            ]);
            InventoryMovement::query()->create([
                'company_id' => $transaction->company_id, 'inventory_transaction_id' => $transaction->id,
                'item_id' => $item->id, 'warehouse_id' => $locked->warehouse_id, 'type' => InventoryMovementType::CostAdjustment,
                'movement_date' => $transaction->transaction_date, 'quantity_in_milli' => 0, 'quantity_out_milli' => 0,
                'unit_cost' => null, 'movement_value' => abs($amount), 'value_delta' => $amount,
                'source_type' => 'inventory_layer', 'source_id' => $locked->id, 'created_by' => $user->id,
            ]);
            $this->postJournal($transaction, $user, [['item' => $item, 'value' => abs($amount)]], $amount > 0 ? 'positive_adjustment' : 'negative_adjustment');
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $header
     * @param  callable(InventoryTransaction):void  $callback
     */
    private function execute(string $companyId, User $user, InventoryMovementType $type, string $date, string $idempotencyKey, array $payload, array $header, callable $callback): InventoryTransaction
    {
        return DB::transaction(function () use ($companyId, $user, $type, $date, $idempotencyKey, $payload, $header, $callback): InventoryTransaction {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $existing = InventoryTransaction::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->idempotency_hash, $hash)) {
                    throw new ConflictHttpException('The idempotency key has already been used for a different inventory request.');
                }

                return $existing->load(['movements.item', 'movements.warehouse', 'journal']);
            }
            $this->journalPostingService->assertOpenPeriod($companyId, $date);
            $sequence = (int) InventoryTransaction::query()->where('company_id', $companyId)->max('sequence') + 1;
            $transaction = InventoryTransaction::query()->create([
                ...$header, 'company_id' => $companyId, 'sequence' => $sequence,
                'number' => sprintf('INV-%s-%05d', date('Y', strtotime($date)), $sequence), 'type' => $type,
                'transaction_date' => $date, 'idempotency_key' => $idempotencyKey,
                'idempotency_hash' => $hash, 'created_by' => $user->id,
            ]);
            $callback($transaction);

            return $transaction->load(['movements.item', 'movements.warehouse', 'movements.consumptions.layer', 'journal']);
        }, 3);
    }

    private function inboundMovement(InventoryTransaction $transaction, InventoryItem $item, Warehouse $warehouse, int $quantity, int $value, ?string $sourceLineId, User $user, ?InventoryMovementType $type = null, ?InventoryMovement $original = null): InventoryMovement
    {
        return InventoryMovement::query()->create([
            'company_id' => $transaction->company_id, 'inventory_transaction_id' => $transaction->id,
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'type' => $type ?? $transaction->type,
            'movement_date' => $transaction->transaction_date, 'quantity_in_milli' => $quantity, 'quantity_out_milli' => 0,
            'unit_cost' => $this->allocation->unitCost($value, $quantity), 'movement_value' => $value, 'value_delta' => $value,
            'source_type' => $transaction->source_type, 'source_id' => $transaction->source_id, 'source_line_id' => $sourceLineId,
            'original_movement_id' => $original?->id, 'created_by' => $user->id,
        ]);
    }

    private function outboundMovement(InventoryTransaction $transaction, InventoryItem $item, Warehouse $warehouse, int $quantity, ?string $sourceLineId, User $user, ?InventoryMovementType $type = null): InventoryMovement
    {
        return InventoryMovement::query()->create([
            'company_id' => $transaction->company_id, 'inventory_transaction_id' => $transaction->id,
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'type' => $type ?? $transaction->type,
            'movement_date' => $transaction->transaction_date, 'quantity_in_milli' => 0, 'quantity_out_milli' => $quantity,
            'movement_value' => 0, 'value_delta' => 0, 'source_type' => $transaction->source_type,
            'source_id' => $transaction->source_id, 'source_line_id' => $sourceLineId, 'created_by' => $user->id,
        ]);
    }

    private function createLayer(InventoryMovement $movement, int $quantity, int $value): InventoryLayer
    {
        return InventoryLayer::query()->create([
            'company_id' => $movement->company_id, 'item_id' => $movement->item_id, 'warehouse_id' => $movement->warehouse_id,
            'source_movement_id' => $movement->id, 'original_quantity_milli' => $quantity, 'remaining_quantity_milli' => $quantity,
            'unit_cost' => $this->allocation->unitCost($value, $quantity), 'original_value' => $value,
            'remaining_value' => $value, 'received_date' => $movement->movement_date,
        ]);
    }

    private function consumeFifo(InventoryMovement $movement, int $quantity): int
    {
        $layers = InventoryLayer::query()->where('company_id', $movement->company_id)->where('item_id', $movement->item_id)->where('warehouse_id', $movement->warehouse_id)->where('remaining_quantity_milli', '>', 0)->orderBy('received_date')->orderBy('id')->lockForUpdate()->get();
        if ($layers->sum('remaining_quantity_milli') < $quantity) {
            throw ValidationException::withMessages(['quantity_milli' => 'Insufficient stock. Negative inventory is not allowed.']);
        }
        $remaining = $quantity;
        $total = 0;
        foreach ($layers as $layer) {
            if ($remaining === 0) {
                break;
            }
            $take = min($remaining, $layer->remaining_quantity_milli);
            $value = $take === $layer->remaining_quantity_milli ? $layer->remaining_value : $this->allocation->proportional($layer->remaining_value, $take, $layer->remaining_quantity_milli);
            InventoryConsumption::query()->create([
                'company_id' => $movement->company_id, 'outbound_movement_id' => $movement->id,
                'inventory_layer_id' => $layer->id, 'quantity_milli' => $take,
                'unit_cost' => $this->allocation->unitCost($value, $take), 'value' => $value,
            ]);
            $layer->update(['remaining_quantity_milli' => $layer->remaining_quantity_milli - $take, 'remaining_value' => $layer->remaining_value - $value]);
            $remaining -= $take;
            $total += $value;
        }

        return $total;
    }

    /** @param Collection<int,InventoryConsumption> $consumptions */
    private function historicalReturnValue(Collection $consumptions, int $skipQuantity, int $returnQuantity): int
    {
        $remainingSkip = $skipQuantity;
        $remainingReturn = $returnQuantity;
        $value = 0;
        foreach ($consumptions as $consumption) {
            $available = $consumption->quantity_milli;
            if ($remainingSkip >= $available) {
                $remainingSkip -= $available;

                continue;
            }
            $start = $remainingSkip;
            $take = min($available - $start, $remainingReturn);
            $before = $this->allocation->proportional($consumption->value, $start, $available);
            $after = $this->allocation->proportional($consumption->value, $start + $take, $available);
            $value += $after - $before;
            $remainingReturn -= $take;
            $remainingSkip = 0;
            if ($remainingReturn === 0) {
                break;
            }
        }
        if ($remainingReturn !== 0) {
            throw ValidationException::withMessages(['quantity_milli' => 'Return quantity exceeds the historical FIFO allocation.']);
        }

        return $value;
    }

    /** @param array<int,array{item:InventoryItem,value:int}> $costs */
    private function postJournal(InventoryTransaction $transaction, User $user, array $costs, string $mode): void
    {
        $lines = [];
        foreach ($costs as $cost) {
            if ($cost['value'] === 0) {
                continue;
            }
            $item = $cost['item'];
            $inventory = $item->inventory_asset_account_id;
            $other = in_array($mode, ['cogs', 'customer_return'], true) ? $item->cogs_account_id : $item->inventory_adjustment_account_id;
            if ($inventory === null || $other === null) {
                throw ValidationException::withMessages(['item' => "Configure inventory accounting accounts for item {$item->sku}."]);
            }
            $debitInventory = in_array($mode, ['positive_adjustment', 'customer_return'], true);
            $lines[] = ['account_id' => $debitInventory ? $inventory : $other, 'description' => "{$transaction->number} — {$item->sku}", 'debit' => $cost['value'], 'credit' => 0, 'related_type' => 'inventory_transaction', 'related_id' => $transaction->id];
            $lines[] = ['account_id' => $debitInventory ? $other : $inventory, 'description' => "{$transaction->number} — {$item->sku}", 'debit' => 0, 'credit' => $cost['value'], 'related_type' => 'inventory_transaction', 'related_id' => $transaction->id];
        }
        if ($lines === []) {
            return;
        }
        $journal = $this->journalPostingService->post($transaction->company_id, $user, [
            'posting_date' => $transaction->transaction_date->format('Y-m-d'), 'reference' => $transaction->number,
            'reference_type' => 'inventory', 'source_id' => $transaction->id, 'source' => $transaction->type->value,
            'description' => "Inventory {$transaction->type->value} — {$transaction->number}", 'lines' => $lines,
        ], "inventory-journal:{$transaction->id}");
        $transaction->update(['journal_id' => $journal->id]);
    }

    private function warehouse(string $companyId, string $warehouseId): Warehouse
    {
        return Warehouse::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($warehouseId);
    }

    private function trackedItem(string $companyId, string $itemId): ?InventoryItem
    {
        $item = InventoryItem::query()->where('company_id', $companyId)->find($itemId);

        return $item?->isTracked() ? $item : null;
    }

    private function requireTrackedItem(string $companyId, string $itemId): InventoryItem
    {
        $item = $this->trackedItem($companyId, $itemId);
        if ($item === null) {
            throw ValidationException::withMessages(['item_id' => 'The selected item is not an active tracked inventory item in this company.']);
        }

        return $item;
    }
}
