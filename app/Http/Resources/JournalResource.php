<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'number' => $this->number,
            'posting_date' => $this->posting_date->format('Y-m-d'), 'reference' => $this->reference ?? '', 'reference_type' => $this->reference_type,
            'source_id' => $this->source_id, 'source' => $this->source, 'description' => $this->description, 'status' => $this->status,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => ['id' => (string) $line->id, 'account_id' => (string) $line->account_id, 'account_code' => $line->account->code, 'account_name' => $line->account->name, 'description' => $line->description ?? '', 'debit' => $line->debit, 'credit' => $line->credit, 'related_type' => $line->related_type, 'related_id' => $line->related_id])),
            'total_debit' => (int) $this->lines->sum('debit'), 'total_credit' => (int) $this->lines->sum('credit'),
            'created_by' => (string) $this->created_by, 'created_at' => $this->created_at?->toISOString(), 'updated_by' => null, 'updated_at' => $this->updated_at?->toISOString(),
            'posted_by' => $this->posted_by ? (string) $this->posted_by : null, 'posted_at' => $this->posted_at?->toISOString(),
            'reverses_journal_id' => $this->reverses_journal_id, 'reversed_by_journal_id' => $this->reversed_by_journal_id,
        ];
    }
}
