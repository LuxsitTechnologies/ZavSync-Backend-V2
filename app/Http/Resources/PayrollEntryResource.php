<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $paid = $this->relationLoaded('paymentAllocations') ? (int) $this->paymentAllocations->sum('amount') : 0;

        return ['id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'payroll_batch_id' => (string) $this->payroll_batch_id, 'employee_id' => (string) $this->employee_id, 'employee_code' => $this->employee_code, 'employee_name' => $this->employee_name, 'department' => $this->department, 'designation' => $this->designation, 'base_salary' => $this->base_salary, 'currency' => $this->currency, 'gross_earnings' => $this->gross_earnings, 'taxable_earnings' => $this->taxable_earnings, 'employee_deductions' => $this->employee_deductions, 'employee_contributions' => $this->employee_contributions, 'tax_amount' => $this->tax_amount, 'employer_contributions' => $this->employer_contributions, 'reimbursements' => $this->reimbursements, 'net_pay' => $this->net_pay, 'employer_total_cost' => $this->employer_total_cost, 'paid_amount' => $paid, 'outstanding_amount' => $this->net_pay - $paid, 'payment_status' => $paid === 0 ? 'UNPAID' : ($paid >= $this->net_pay ? 'PAID' : 'PARTIALLY_PAID'), 'released_at' => $this->released_at?->toIso8601String(), 'released_by' => $this->released_by, 'profile_snapshot' => $this->profile_snapshot, 'statutory_rule_snapshot' => $this->statutory_rule_snapshot, 'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => ['id' => $line->id, 'component_code' => $line->component_code, 'component_name' => $line->component_name, 'component_type' => $line->component_type, 'amount' => $line->amount, 'is_taxable' => $line->is_taxable, 'gl_account_id' => $line->gl_account_id, 'liability_account_id' => $line->liability_account_id, 'calculation_snapshot' => $line->calculation_snapshot])), 'adjustments' => $this->whenLoaded('adjustments')];
    }
}
