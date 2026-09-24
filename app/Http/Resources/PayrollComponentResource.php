<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollComponentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'code' => $this->code, 'name' => $this->name, 'type' => $this->type, 'calculation_method' => $this->calculation_method, 'fixed_amount' => $this->fixed_amount, 'rate_bps' => $this->rate_bps, 'calculation_base' => $this->calculation_base, 'is_taxable' => $this->is_taxable, 'is_active' => $this->is_active, 'effective_from' => $this->effective_from?->format('Y-m-d'), 'effective_to' => $this->effective_to?->format('Y-m-d'), 'gl_account_id' => $this->gl_account_id, 'liability_account_id' => $this->liability_account_id, 'description' => $this->description, 'gl_account' => $this->whenLoaded('glAccount', fn (): ?array => $this->glAccount ? ['id' => $this->glAccount->id, 'code' => $this->glAccount->code, 'name' => $this->glAccount->name] : null), 'liability_account' => $this->whenLoaded('liabilityAccount', fn (): ?array => $this->liabilityAccount ? ['id' => $this->liabilityAccount->id, 'code' => $this->liabilityAccount->code, 'name' => $this->liabilityAccount->name] : null)];
    }
}
