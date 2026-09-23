<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'code' => $this->code,
            'name' => $this->name, 'location' => $this->location, 'is_active' => $this->is_active,
            'is_default' => $this->is_default, 'quantity_on_hand_milli' => (int) ($this->quantity_on_hand_milli ?? 0),
            'inventory_value' => (int) ($this->inventory_value ?? 0), 'created_by' => $this->created_by,
            'updated_by' => $this->updated_by, 'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
