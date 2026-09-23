<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierPaymentResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'supplier_id' => (string) $this->supplier_id,
            'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier->name), 'number' => $this->number,
            'payment_number' => $this->number, 'payment_date' => $this->payment_date->format('Y-m-d'),
            'posting_date' => $this->posting_date->format('Y-m-d'), 'amount' => $this->amount, 'method' => $this->method->value,
            'bank_account_id' => (string) $this->bank_account_id, 'reference' => $this->reference, 'notes' => $this->notes,
            'journal_id' => (string) $this->journal_id, 'created_by' => (string) $this->created_by, 'created_at' => $this->created_at?->toISOString(),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($allocation): array => [
                'id' => (string) $allocation->id, 'supplier_bill_id' => (string) $allocation->supplier_bill_id,
                'bill_number' => $allocation->relationLoaded('bill') ? $allocation->bill->bill_number : null, 'amount' => $allocation->amount,
            ])),
        ];
    }
}
