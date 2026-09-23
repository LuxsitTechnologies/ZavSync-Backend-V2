<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'company_id' => (string) $this->company_id,
            'number' => $this->number, 'type' => $this->type->value,
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'source_warehouse_id' => $this->source_warehouse_id, 'destination_warehouse_id' => $this->destination_warehouse_id,
            'source_type' => $this->source_type, 'source_id' => $this->source_id, 'reference' => $this->reference,
            'reason' => $this->reason, 'notes' => $this->notes, 'journal_id' => $this->journal_id,
            'movements' => $this->whenLoaded('movements', fn () => $this->movements->map(fn ($movement): array => [
                'id' => (string) $movement->id, 'item_id' => (string) $movement->item_id,
                'warehouse_id' => (string) $movement->warehouse_id, 'type' => $movement->type->value,
                'quantity_in_milli' => $movement->quantity_in_milli, 'quantity_out_milli' => $movement->quantity_out_milli,
                'unit_cost' => $movement->unit_cost, 'movement_value' => $movement->movement_value,
                'value_delta' => $movement->value_delta, 'source_line_id' => $movement->source_line_id,
            ])),
            'created_by' => $this->created_by, 'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
