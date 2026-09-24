<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollLiabilitySettlementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'number' => $this->number, 'payroll_batch_id' => $this->payroll_batch_id, 'liability_type' => $this->liability_type, 'financial_account_id' => (string) $this->financial_account_id, 'payment_date' => $this->payment_date?->format('Y-m-d'), 'amount' => $this->amount, 'currency' => $this->currency, 'reference' => $this->reference, 'notes' => $this->notes, 'journal_id' => $this->journal_id, 'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($allocation): array => ['id' => $allocation->id, 'payroll_entry_line_id' => $allocation->payroll_entry_line_id, 'amount' => $allocation->amount]))];
    }
}
