<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollPaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'number' => $this->number, 'payroll_batch_id' => (string) $this->payroll_batch_id, 'financial_account_id' => (string) $this->financial_account_id, 'payment_date' => $this->payment_date?->format('Y-m-d'), 'amount' => $this->amount, 'currency' => $this->currency, 'reference' => $this->reference, 'notes' => $this->notes, 'journal_id' => $this->journal_id, 'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($allocation): array => ['id' => $allocation->id, 'payroll_entry_id' => $allocation->payroll_entry_id, 'amount' => $allocation->amount, 'employee' => $allocation->relationLoaded('entry') ? ['id' => $allocation->entry->employee_id, 'employee_code' => $allocation->entry->employee_code, 'full_name' => $allocation->entry->employee_name] : null]))];
    }
}
