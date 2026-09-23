<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'code' => $this->code,
            'name' => $this->name, 'legal_name' => $this->legal_name, 'contact_person' => $this->contact_person,
            'email' => $this->email, 'phone' => $this->phone, 'billing_address' => $this->billing_address,
            'address' => $this->billing_address, 'city' => $this->city, 'province' => $this->province, 'country' => $this->country,
            'postal_code' => $this->postal_code, 'ntn' => $this->ntn, 'tax_number' => $this->ntn, 'cnic' => $this->cnic,
            'strn' => $this->strn, 'tax_status' => $this->tax_status, 'payment_terms_days' => $this->payment_terms_days,
            'currency' => $this->currency, 'default_expense_account_id' => $this->default_expense_account_id,
            'default_payable_account_id' => $this->default_payable_account_id, 'is_active' => $this->is_active,
            'status' => $this->is_active ? 'active' : 'inactive', 'notes' => $this->notes,
            'outstanding' => (int) ($this->outstanding ?? 0), 'created_by' => (string) $this->created_by,
            'updated_by' => $this->updated_by, 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
