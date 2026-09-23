<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class InventoryReportingService
{
    /** @param array<string,mixed> $filters @return Collection<int,array<string,mixed>> */
    public function ledger(string $companyId, array $filters): Collection
    {
        $query = InventoryMovement::query()->where('inventory_movements.company_id', $companyId)
            ->with(['item', 'warehouse', 'transaction'])->orderBy('movement_date')->orderBy('created_at')->orderBy('id');
        $this->movementFilters($query, $filters, false);
        $running = [];

        return $query->get()->map(function (InventoryMovement $movement) use (&$running): array {
            $key = $movement->item_id.'|'.$movement->warehouse_id;
            $balance = $running[$key] ?? ['quantity' => 0, 'value' => 0];
            $balance['quantity'] += $movement->quantity_in_milli - $movement->quantity_out_milli;
            $balance['value'] += $movement->value_delta;
            $running[$key] = $balance;

            return [
                'id' => (string) $movement->id, 'company_id' => (string) $movement->company_id,
                'item_id' => (string) $movement->item_id, 'item_sku' => $movement->item->sku, 'item_name' => $movement->item->name,
                'warehouse_id' => (string) $movement->warehouse_id, 'warehouse' => $movement->warehouse->name,
                'date' => $movement->movement_date->format('Y-m-d'), 'type' => $movement->type->value,
                'reference' => $movement->transaction->reference ?? $movement->transaction->number,
                'reference_type' => $movement->source_type ?? 'inventory', 'quantity_in' => $movement->quantity_in_milli,
                'quantity_out' => $movement->quantity_out_milli, 'unit_cost' => $movement->unit_cost,
                'value' => $movement->movement_value, 'value_delta' => $movement->value_delta,
                'running_quantity' => $balance['quantity'], 'running_value' => $balance['value'],
                'journal_id' => $movement->transaction->journal_id,
            ];
        })->when(! empty($filters['from']), fn (Collection $rows): Collection => $rows
            ->filter(fn (array $row): bool => $row['date'] >= $filters['from'])
            ->values());
    }

    /** @param array<string,mixed> $filters @return Collection<int,array<string,mixed>> */
    public function valuation(string $companyId, array $filters): Collection
    {
        $query = InventoryMovement::query()->where('inventory_movements.company_id', $companyId)
            ->join('inventory_items', 'inventory_items.id', '=', 'inventory_movements.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_movements.warehouse_id')
            ->selectRaw('inventory_movements.item_id, inventory_movements.warehouse_id, inventory_items.sku, inventory_items.name, inventory_items.category, warehouses.name as warehouse, SUM(quantity_in_milli) - SUM(quantity_out_milli) as quantity_on_hand_milli, SUM(value_delta) as inventory_value')
            ->groupBy('inventory_movements.item_id', 'inventory_movements.warehouse_id', 'inventory_items.sku', 'inventory_items.name', 'inventory_items.category', 'warehouses.name');
        if (! empty($filters['as_of'])) {
            $query->whereDate('movement_date', '<=', $filters['as_of']);
        }
        if (! empty($filters['warehouse_id'])) {
            $query->where('inventory_movements.warehouse_id', $filters['warehouse_id']);
        }
        if (! empty($filters['item_id'])) {
            $query->where('inventory_movements.item_id', $filters['item_id']);
        }
        if (! empty($filters['category'])) {
            $query->where('inventory_items.category', $filters['category']);
        }

        return $query->orderBy('inventory_items.sku')->get()->map(fn ($row): array => [
            'id' => $row->item_id.'-'.$row->warehouse_id, 'company_id' => $companyId,
            'item_id' => (string) $row->item_id, 'sku' => $row->sku, 'name' => $row->name, 'category' => $row->category,
            'warehouse_id' => (string) $row->warehouse_id, 'warehouse' => $row->warehouse,
            'quantity' => (int) $row->quantity_on_hand_milli, 'quantity_on_hand_milli' => (int) $row->quantity_on_hand_milli,
            'value' => (int) $row->inventory_value, 'inventory_value' => (int) $row->inventory_value,
            'average_unit_cost' => (int) $row->quantity_on_hand_milli === 0 ? 0 : $this->unitCost((int) $row->inventory_value, (int) $row->quantity_on_hand_milli),
        ])->values();
    }

    /** @param array<string,mixed> $filters @return Collection<int,array<string,mixed>> */
    public function layers(string $companyId, array $filters): Collection
    {
        $query = InventoryLayer::query()->where('inventory_layers.company_id', $companyId)->where('remaining_quantity_milli', '>', 0)->with(['item', 'warehouse', 'sourceMovement.transaction'])->orderBy('received_date')->orderBy('id');
        if (! empty($filters['item_id'])) {
            $query->where('item_id', $filters['item_id']);
        }
        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        return $query->get()->map(fn (InventoryLayer $layer): array => [
            'id' => (string) $layer->id, 'company_id' => (string) $layer->company_id, 'item_id' => (string) $layer->item_id,
            'item_sku' => $layer->item->sku, 'item_name' => $layer->item->name, 'warehouse_id' => (string) $layer->warehouse_id,
            'warehouse' => $layer->warehouse->name, 'received_date' => $layer->received_date->format('Y-m-d'),
            'reference' => $layer->sourceMovement->transaction->reference ?? $layer->sourceMovement->transaction->number,
            'original_quantity' => $layer->original_quantity_milli, 'original_quantity_milli' => $layer->original_quantity_milli,
            'remaining_quantity' => $layer->remaining_quantity_milli, 'remaining_quantity_milli' => $layer->remaining_quantity_milli,
            'unit_cost' => $layer->unit_cost, 'original_value' => $layer->original_value, 'remaining_value' => $layer->remaining_value,
            'age_days' => $layer->received_date->diffInDays(CarbonImmutable::today()),
        ]);
    }

    /** @return Collection<int,array<string,mixed>> */
    public function lowStock(string $companyId): Collection
    {
        $valuation = $this->valuation($companyId, [])->groupBy('item_id')->map(fn (Collection $rows): int => $rows->sum('quantity_on_hand_milli'));

        return InventoryItem::query()->where('company_id', $companyId)->where('track_inventory', true)->where('is_active', true)->orderBy('name')->get()->map(function ($item) use ($valuation): array {
            $quantity = (int) ($valuation->get($item->id) ?? 0);

            return ['id' => (string) $item->id, 'sku' => $item->sku, 'name' => $item->name, 'quantity_on_hand_milli' => $quantity, 'reorder_level_milli' => $item->reorder_level_milli, 'reorder_quantity_milli' => $item->reorder_quantity_milli, 'status' => $quantity <= 0 ? 'out_of_stock' : 'low_stock'];
        })->filter(fn (array $row): bool => $row['quantity_on_hand_milli'] <= $row['reorder_level_milli'])->values();
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function reconciliation(string $companyId, array $filters): array
    {
        $valuation = $this->valuation($companyId, $filters)->sum('inventory_value');
        $accountIds = InventoryItem::query()->where('company_id', $companyId)->whereNotNull('inventory_asset_account_id')->distinct()->pluck('inventory_asset_account_id');
        $glQuery = JournalLine::query()->whereIn('account_id', $accountIds)->whereHas('journal', fn (Builder $query) => $query->where('company_id', $companyId)->where('status', 'posted'));
        if (! empty($filters['as_of'])) {
            $glQuery->whereHas('journal', fn (Builder $query) => $query->whereDate('posting_date', '<=', $filters['as_of']));
        }
        $gl = (int) $glQuery->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')->value('balance');
        $difference = $valuation - $gl;

        return ['as_of' => $filters['as_of'] ?? now()->toDateString(), 'inventory_valuation' => $valuation, 'gl_inventory_balance' => $gl, 'difference' => $difference, 'status' => $difference === 0 ? 'reconciled' : 'difference', 'explanation' => $difference === 0 ? 'FIFO valuation agrees with the inventory asset ledger.' : 'Review unbilled receipts, supplier-bill timing, and explicit inventory cost adjustments.'];
    }

    /** @return Collection<int,array<string,mixed>> */
    public function cogs(string $companyId, ?string $itemId = null): Collection
    {
        $query = InventoryMovement::query()->where('company_id', $companyId)->where('type', 'sale_issue')->with(['item', 'transaction.journal', 'consumptions.layer']);
        if ($itemId !== null && $itemId !== '') {
            $query->where('item_id', $itemId);
        }

        return $query->orderByDesc('movement_date')->get()->map(fn (InventoryMovement $movement): array => [
            'id' => (string) $movement->id, 'company_id' => (string) $movement->company_id, 'item_id' => (string) $movement->item_id,
            'date' => $movement->movement_date->format('Y-m-d'), 'reference' => $movement->transaction->reference ?? $movement->transaction->number,
            'quantity' => $movement->quantity_out_milli, 'cogs_amount' => $movement->movement_value,
            'posted' => $movement->transaction->journal_id !== null, 'journal_id' => $movement->transaction->journal_id,
            'consumption' => $movement->consumptions->map(fn ($consumption): array => [
                'layer_id' => (string) $consumption->inventory_layer_id, 'received_date' => $consumption->layer->received_date->format('Y-m-d'),
                'quantity' => $consumption->quantity_milli, 'unit_cost' => $consumption->unit_cost, 'value' => $consumption->value,
            ]),
        ]);
    }

    /** @param Builder<InventoryMovement> $query @param array<string,mixed> $filters */
    private function movementFilters(Builder $query, array $filters, bool $includeFrom = true): void
    {
        if (! empty($filters['item_id'])) {
            $query->where('item_id', $filters['item_id']);
        }
        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }
        if ($includeFrom && ! empty($filters['from'])) {
            $query->whereDate('movement_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('movement_date', '<=', $filters['to']);
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $builder) use ($search): void {
                $builder->whereHas('item', fn (Builder $itemQuery) => $itemQuery
                    ->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%"))
                    ->orWhereHas('transaction', fn (Builder $transactionQuery) => $transactionQuery
                        ->where('number', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%"));
            });
        }
    }

    private function unitCost(int $value, int $quantity): int
    {
        return intdiv(($value * 1000) + intdiv($quantity, 2), $quantity);
    }
}
