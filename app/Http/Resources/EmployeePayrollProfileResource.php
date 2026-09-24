<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeePayrollProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'employee_id' => (string) $this->employee_id, 'payroll_status' => $this->payroll_status, 'pay_frequency' => $this->pay_frequency, 'base_salary' => $this->base_salary, 'currency' => $this->currency, 'effective_from' => $this->effective_from?->format('Y-m-d'), 'effective_to' => $this->effective_to?->format('Y-m-d'), 'tax_identifier' => $this->tax_identifier, 'statutory_registration' => $this->statutory_registration, 'payment_financial_account_id' => $this->payment_financial_account_id, 'employee_bank_reference' => $this->employee_bank_reference, 'components' => $this->whenLoaded('components', fn () => $this->components->map(fn ($assignment): array => ['id' => $assignment->id, 'payroll_component_id' => $assignment->payroll_component_id, 'fixed_amount' => $assignment->fixed_amount, 'rate_bps' => $assignment->rate_bps, 'effective_from' => $assignment->effective_from?->format('Y-m-d'), 'effective_to' => $assignment->effective_to?->format('Y-m-d'), 'is_active' => $assignment->is_active, 'component' => $assignment->relationLoaded('component') ? new PayrollComponentResource($assignment->component) : null]))];
    }
}
