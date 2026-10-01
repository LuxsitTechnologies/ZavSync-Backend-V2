<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LegacyImportRunResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'source_system' => $this->source_system, 'source_company_id' => $this->source_company_id, 'status' => $this->status, 'mode' => $this->mode, 'source_filename' => $this->source_filename, 'source_manifest' => $this->source_manifest, 'progress' => $this->progress, 'reconciliation' => $this->reconciliation, 'failure_message' => $this->failure_message, 'started_at' => $this->started_at?->toIso8601String(), 'completed_at' => $this->completed_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String()];
    }
}
