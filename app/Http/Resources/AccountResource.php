<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
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
            'code' => $this->code, 'name' => $this->name, 'type' => $this->type, 'subtype' => $this->subtype,
            'normal_balance' => $this->normal_balance,
            'parent_id' => $this->parent_id, 'is_active' => $this->is_active, 'is_system' => $this->is_system,
            'description' => $this->description, 'currency' => $this->currency,
            'opening_balance' => $this->opening_balance, 'opening_balance_date' => $this->opening_balance_date?->format('Y-m-d'),
            'balance' => $this->opening_balance + $this->movementBalance((int) ($this->posted_debit ?? 0), (int) ($this->posted_credit ?? 0)),
            'transaction_count' => (int) ($this->transaction_count ?? 0),
            'depth' => (int) ($this->depth ?? 0), 'has_children' => (bool) ($this->has_children ?? false),
            'created_by' => (string) $this->created_by, 'created_at' => $this->created_at?->toISOString(),
            'updated_by' => $this->updated_by ? (string) $this->updated_by : null, 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
