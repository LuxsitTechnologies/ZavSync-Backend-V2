<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
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
            'name' => $this->name, 'legal_name' => $this->legal_name, 'type' => $this->type,
            'ntn' => $this->ntn, 'cnic' => $this->cnic, 'strn' => $this->strn, 'tax_number' => $this->ntn ?? $this->cnic ?? '',
            'email' => $this->email ?? '', 'phone' => $this->phone ?? '', 'billing_address' => $this->billing_address,
            'city' => $this->city, 'province' => $this->province, 'country' => $this->country, 'postal_code' => $this->postal_code,
            'contact_person' => $this->contact_person, 'payment_terms_days' => $this->payment_terms_days,
            'credit_limit' => $this->credit_limit, 'currency' => $this->currency, 'tax_metadata' => $this->tax_metadata,
            'is_active' => $this->is_active, 'notes' => $this->notes, 'outstanding' => (int) ($this->outstanding ?? 0),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
