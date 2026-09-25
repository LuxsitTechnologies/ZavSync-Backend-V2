<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutreachSuppressionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'email' => $this->email, 'reason' => $this->reason, 'source' => $this->source, 'details' => $this->details, 'is_active' => $this->is_active, 'suppressed_at' => $this->suppressed_at?->toIso8601String(), 'removed_at' => $this->removed_at?->toIso8601String()];
    }
}
