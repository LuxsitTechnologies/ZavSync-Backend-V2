<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CrmPipelineResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'companyId' => (string) $this->company_id, 'name' => $this->name, 'description' => $this->description, 'isActive' => $this->is_active, 'isDefault' => $this->is_default, 'stages' => CrmPipelineStageResource::collection($this->whenLoaded('stages')), 'dealsCount' => (int) ($this->deals_count ?? 0)];
    }
}
