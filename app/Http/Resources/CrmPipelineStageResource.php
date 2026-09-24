<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CrmPipelineStageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'pipelineId' => (string) $this->pipeline_id, 'name' => $this->name, 'position' => $this->position, 'probabilityBasisPoints' => $this->probability_bps, 'isWon' => $this->is_won, 'isLost' => $this->is_lost, 'isActive' => $this->is_active];
    }
}
