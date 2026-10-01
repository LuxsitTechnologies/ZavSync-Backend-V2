<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MigrationExceptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'import_run_id' => $this->import_run_id, 'source_entity_type' => $this->source_entity_type, 'source_id' => $this->source_id, 'target_id' => $this->target_id, 'exception_code' => $this->exception_code, 'severity' => $this->severity, 'safe_metadata' => $this->safe_metadata, 'resolution_state' => $this->resolution_state, 'resolution_note' => $this->resolution_note, 'resolved_at' => $this->resolved_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String()];
    }
}
