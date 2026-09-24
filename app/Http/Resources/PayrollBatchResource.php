<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollBatchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'payroll_period_id' => (string) $this->payroll_period_id, 'number' => $this->number, 'status' => $this->status, 'accounting_date' => $this->accounting_date?->format('Y-m-d'), 'employee_count' => $this->employee_count, 'gross_earnings' => $this->gross_earnings, 'taxable_earnings' => $this->taxable_earnings, 'employee_deductions' => $this->employee_deductions, 'employee_contributions' => $this->employee_contributions, 'tax_amount' => $this->tax_amount, 'employer_contributions' => $this->employer_contributions, 'reimbursements' => $this->reimbursements, 'net_pay' => $this->net_pay, 'employer_total_cost' => $this->employer_total_cost, 'journal_id' => $this->journal_id, 'reversal_journal_id' => $this->reversal_journal_id, 'correction_of_batch_id' => $this->correction_of_batch_id, 'correction_reason' => $this->correction_reason, 'reviewed_at' => $this->reviewed_at?->toISOString(), 'approved_at' => $this->approved_at?->toISOString(), 'posted_at' => $this->posted_at?->toISOString(), 'period' => new PayrollPeriodResource($this->whenLoaded('period')), 'entries' => PayrollEntryResource::collection($this->whenLoaded('entries')), 'journal' => new JournalResource($this->whenLoaded('journal'))];
    }
}
