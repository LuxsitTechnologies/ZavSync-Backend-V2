<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailSendingIdentityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'provider_connection_id' => $this->provider_connection_id, 'connection_name' => $this->whenLoaded('connection', fn () => $this->connection->name), 'from_email' => $this->from_email, 'from_name' => $this->from_name, 'reply_to_email' => $this->reply_to_email, 'verification_status' => $this->verification_status, 'is_default' => $this->is_default, 'is_active' => $this->is_active, 'verified_at' => $this->verified_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String()];
    }
}
