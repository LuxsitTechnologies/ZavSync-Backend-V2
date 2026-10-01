<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FbrCompanyConfigurationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'company_id' => $this->company_id, 'seller_tax_identifier' => $this->seller_tax_identifier,
            'seller_business_name' => $this->seller_business_name, 'seller_province' => $this->seller_province,
            'seller_address' => $this->seller_address, 'environment' => $this->environment,
            'connection_state' => $this->connection_state, 'credential_configured' => filled($this->credential),
            'last_verified_at' => $this->last_verified_at?->toIso8601String(), 'last_error' => $this->last_error,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
