<?php

namespace App\Http\Requests\Api\V1\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'payroll.configure') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');

        return ['employee_code' => ['required', 'string', 'max:50', Rule::unique('employees')->where('company_id', $companyId)], 'full_name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255', Rule::unique('employees')->where('company_id', $companyId)], 'phone' => ['nullable', 'string', 'max:40'], 'department' => ['nullable', 'string', 'max:255'], 'designation' => ['nullable', 'string', 'max:255'], 'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contract', 'intern'])], 'status' => ['required', Rule::in(['active', 'probation', 'on_leave', 'notice_period', 'resigned', 'terminated'])], 'joining_date' => ['required', 'date'], 'leaving_date' => ['nullable', 'date', 'after_or_equal:joining_date'], 'location' => ['nullable', 'string', 'max:255']];
    }
}
