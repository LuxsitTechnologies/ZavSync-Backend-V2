<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailProviderConnectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'name' => $this->name, 'provider_type' => $this->provider_type, 'status' => $this->status, 'credentials_configured' => $this->credentials !== null || $this->access_token !== null, 'token_expires_at' => $this->token_expires_at?->toIso8601String(), 'last_verified_at' => $this->last_verified_at?->toIso8601String(), 'last_error' => $this->last_error, 'identities_count' => $this->whenCounted('identities'), 'created_at' => $this->created_at?->toIso8601String(), 'updated_at' => $this->updated_at?->toIso8601String()];
    }
}
