<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'sku' => $this->sku,
            'name' => $this->name, 'description' => $this->description, 'type' => $this->type->value,
            'track_inventory' => $this->track_inventory, 'unit' => $this->unit, 'sales_unit' => $this->sales_unit,
            'purchase_unit' => $this->purchase_unit, 'category' => $this->category, 'barcode' => $this->barcode,
            'is_active' => $this->is_active, 'sales_price' => $this->sales_price,
            'default_purchase_cost' => $this->default_purchase_cost, 'reorder_level_milli' => $this->reorder_level_milli,
            'reorder_quantity_milli' => $this->reorder_quantity_milli,
            'inventory_asset_account_id' => $this->inventory_asset_account_id, 'cogs_account_id' => $this->cogs_account_id,
            'sales_account_id' => $this->sales_account_id, 'inventory_adjustment_account_id' => $this->inventory_adjustment_account_id,
            'quantity_on_hand_milli' => (int) ($this->quantity_on_hand_milli ?? 0), 'inventory_value' => (int) ($this->inventory_value ?? 0),
            'created_by' => $this->created_by, 'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
