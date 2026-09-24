<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CrmScoreRuleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'companyId' => (string) $this->company_id, 'name' => $this->name, 'targetType' => $this->target_type, 'field' => $this->field, 'operator' => $this->operator, 'comparisonValue' => $this->comparison_value, 'points' => $this->points, 'position' => $this->position, 'isActive' => $this->is_active];
    }
}
