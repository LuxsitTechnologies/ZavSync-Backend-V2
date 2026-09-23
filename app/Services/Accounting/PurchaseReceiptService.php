<?php

namespace App\Services\Accounting;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseReceiptStatus;
use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReceipt;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchaseReceiptService
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    /** @param array<string,mixed> $data */
    public function receive(string $companyId, User $user, PurchaseOrder $order, array $data, string $idempotencyKey): PurchaseReceipt
    {
        return DB::transaction(function () use ($companyId, $user, $order, $data, $idempotencyKey): PurchaseReceipt {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $hash = hash('sha256', json_encode(['purchase_order_id' => $order->id, ...$data], JSON_THROW_ON_ERROR));
            $existing = PurchaseReceipt::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->idempotency_hash, $hash)) {
                    throw new ConflictHttpException('The idempotency key has already been used for a different receipt.');
                }

                if ($existing->warehouse_id !== null) {
                    $this->inventoryService->receivePurchaseReceipt($companyId, $user, $existing, $existing->warehouse()->firstOrFail());
                }

                return $existing->load(['supplier', 'purchaseOrder', 'warehouse', 'inventoryTransaction', 'lines.purchaseOrderLine']);
            }

            $order = PurchaseOrder::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($order->id);
            if (! $order->status->canReceive()) {
                throw ValidationException::withMessages(['purchase_order' => 'Goods or services can only be received against an approved purchase order.']);
            }

            $lineIds = collect($data['lines'])->pluck('purchase_order_line_id')->all();
            $orderLines = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->whereIn('id', $lineIds)->lockForUpdate()->get()->keyBy('id');
            if ($orderLines->count() !== count($lineIds)) {
                throw ValidationException::withMessages(['lines' => 'Every receipt line must belong to this purchase order.']);
            }

            $trackedItemIds = $orderLines->where('procurement_type', 'goods')->pluck('item_id')->filter()->unique();
            $hasTrackedInventory = InventoryItem::query()->where('company_id', $companyId)->whereIn('id', $trackedItemIds)->where('type', 'inventory')->where('track_inventory', true)->exists();
            $warehouse = null;
            if ($hasTrackedInventory) {
                if (! isset($data['warehouse_id'])) {
                    throw ValidationException::withMessages(['warehouse_id' => 'A warehouse is required when receiving tracked inventory items.']);
                }
                $warehouse = Warehouse::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($data['warehouse_id']);
            }

            $sequence = (int) PurchaseReceipt::query()->where('company_id', $companyId)->max('sequence') + 1;
            $receipt = PurchaseReceipt::query()->create([
                'company_id' => $companyId,
                'purchase_order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'warehouse_id' => $warehouse?->id,
                'sequence' => $sequence,
                'number' => sprintf('GRN-%s-%04d', date('Y', strtotime($data['receipt_date'])), $sequence),
                'receipt_date' => $data['receipt_date'],
                'status' => PurchaseReceiptStatus::Partial,
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'idempotency_hash' => $hash,
                'received_by' => $user->id,
            ]);

            foreach ($data['lines'] as $index => $input) {
                /** @var PurchaseOrderLine $line */
                $line = $orderLines->get($input['purchase_order_line_id']);
                $quantity = (int) $input['quantity_received_milli'];
                $remaining = $line->quantity_milli - $line->received_quantity_milli;
                if ($quantity > $remaining) {
                    throw ValidationException::withMessages(["lines.$index.quantity_received_milli" => 'The received quantity cannot exceed the remaining ordered quantity.']);
                }
                $receipt->lines()->create([
                    'purchase_order_line_id' => $line->id,
                    'ordered_quantity_milli' => $line->quantity_milli,
                    'previously_received_quantity_milli' => $line->received_quantity_milli,
                    'quantity_received_milli' => $quantity,
                    'remaining_quantity_milli' => $remaining - $quantity,
                ]);
                $line->increment('received_quantity_milli', $quantity);
            }

            $fullyReceived = ! PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->whereColumn('received_quantity_milli', '<', 'quantity_milli')->exists();
            $receipt->update(['status' => $fullyReceived ? PurchaseReceiptStatus::Complete : PurchaseReceiptStatus::Partial]);
            $order->update(['status' => $fullyReceived ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartiallyReceived]);

            if ($warehouse !== null) {
                $this->inventoryService->receivePurchaseReceipt($companyId, $user, $receipt, $warehouse);
            }

            return $receipt->load(['supplier', 'purchaseOrder', 'warehouse', 'inventoryTransaction', 'lines.purchaseOrderLine']);
        });
    }
}
