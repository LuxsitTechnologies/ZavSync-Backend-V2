<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'employee_code' => $this->employee_code, 'full_name' => $this->full_name, 'email' => $this->email, 'phone' => $this->phone, 'department' => $this->department, 'designation' => $this->designation, 'employment_type' => $this->employment_type, 'status' => $this->status, 'joining_date' => $this->joining_date?->format('Y-m-d'), 'leaving_date' => $this->leaving_date?->format('Y-m-d'), 'location' => $this->location, 'payroll_profile' => new EmployeePayrollProfileResource($this->whenLoaded('currentPayrollProfile'))];
    }
}
