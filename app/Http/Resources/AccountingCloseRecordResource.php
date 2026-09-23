<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountingCloseRecordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'close_type' => $this->close_type, 'accounting_period_id' => $this->accounting_period_id, 'fiscal_year_id' => $this->fiscal_year_id, 'status' => $this->status, 'checklist_snapshot' => $this->checklist_snapshot, 'reason' => $this->reason, 'closing_journal_id' => $this->closing_journal_id, 'reversal_journal_id' => $this->reversal_journal_id, 'closed_by' => $this->closed_by, 'closed_at' => $this->closed_at?->toISOString(), 'reopened_by' => $this->reopened_by, 'reopened_at' => $this->reopened_at?->toISOString()];
    }
}
