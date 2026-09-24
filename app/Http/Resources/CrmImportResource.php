<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CrmImportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'companyId' => (string) $this->company_id, 'entityType' => $this->entity_type,
            'filename' => $this->original_filename, 'status' => $this->status, 'mapping' => $this->mapping,
            'summary' => $this->summary, 'confirmedAt' => $this->confirmed_at?->toISOString(),
            'rows' => $this->whenLoaded('rows', fn () => $this->rows->map(fn ($row): array => ['id' => $row->id, 'rowNumber' => $row->row_number, 'source' => $row->source_data, 'mapped' => $row->mapped_data, 'state' => $row->state, 'errors' => $row->errors ?? [], 'warnings' => $row->warnings ?? [], 'createdRecordId' => $row->created_record_id])),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
