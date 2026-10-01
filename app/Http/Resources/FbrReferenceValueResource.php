<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FbrReferenceValueResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'category' => $this->category, 'code' => $this->code, 'label' => $this->label, 'parent_code' => $this->parent_code, 'metadata' => $this->metadata, 'source' => $this->source, 'source_version' => $this->source_version, 'is_active' => $this->is_active, 'valid_from' => $this->valid_from?->format('Y-m-d'), 'valid_until' => $this->valid_until?->format('Y-m-d')];
    }
}
