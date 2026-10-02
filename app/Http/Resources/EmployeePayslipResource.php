<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeePayslipResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $paid = (int) ($this->paid_amount ?? 0);

        return [
            'id' => (string) $this->id,
            'released_at' => $this->released_at->toIso8601String(),
            'company' => ['id' => (string) $this->batch->company_id, 'name' => $this->batch->company->name],
            'employee' => [
                'id' => (string) $this->employee_id,
                'employee_code' => $this->employee_code,
                'full_name' => $this->employee_name,
                'department' => $this->department,
                'designation' => $this->designation,
            ],
            'payroll' => [
                'batch_number' => $this->batch->number,
                'batch_status' => $this->batch->status,
                'posted_at' => $this->batch->posted_at?->toIso8601String(),
                'period' => [
                    'name' => $this->batch->period->name,
                    'period_start' => $this->batch->period->period_start->format('Y-m-d'),
                    'period_end' => $this->batch->period->period_end->format('Y-m-d'),
                    'pay_date' => $this->batch->period->pay_date->format('Y-m-d'),
                ],
            ],
            'currency' => $this->currency,
            'base_salary' => $this->base_salary,
            'gross_earnings' => $this->gross_earnings,
            'employee_deductions' => $this->employee_deductions,
            'employee_contributions' => $this->employee_contributions,
            'tax_amount' => $this->tax_amount,
            'reimbursements' => $this->reimbursements,
            'net_pay' => $this->net_pay,
            'paid_amount' => $paid,
            'outstanding_amount' => $this->net_pay - $paid,
            'payment_status' => $paid === 0 ? 'UNPAID' : ($paid >= $this->net_pay ? 'PAID' : 'PARTIALLY_PAID'),
            'earnings_lines' => $this->whenLoaded('lines', fn (): array => $this->lines
                ->whereIn('component_type', ['EARNINGS', 'REIMBURSEMENTS'])
                ->map(fn ($line): array => ['component_code' => $line->component_code, 'component_name' => $line->component_name, 'component_type' => $line->component_type, 'amount' => $line->amount])
                ->values()->all()),
            'deduction_lines' => $this->whenLoaded('lines', fn (): array => $this->lines
                ->whereIn('component_type', ['DEDUCTIONS', 'EMPLOYEE_CONTRIBUTIONS', 'TAX'])
                ->map(fn ($line): array => ['component_code' => $line->component_code, 'component_name' => $line->component_name, 'component_type' => $line->component_type, 'amount' => $line->amount])
                ->values()->all()),
        ];
    }
}
