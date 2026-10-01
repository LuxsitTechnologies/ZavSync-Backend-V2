<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LegacyFbrEvidenceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'invoice_id' => $this->invoice_id, 'original_status' => $this->original_status, 'normalized_status' => $this->normalized_status, 'fbr_reference_number' => $this->fbr_reference_number, 'retry_count' => $this->retry_count, 'last_attempt_at' => $this->last_attempt_at?->toIso8601String(), 'source_created_at' => $this->source_created_at?->toIso8601String(), 'source_updated_at' => $this->source_updated_at?->toIso8601String(), 'sanitized_response' => $this->sanitized_response, 'requires_review' => $this->requires_review, 'submission_blocked' => $this->submission_blocked];
    }
}
