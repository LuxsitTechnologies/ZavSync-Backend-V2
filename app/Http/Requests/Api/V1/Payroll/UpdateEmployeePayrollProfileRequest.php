<?php

namespace App\Http\Requests\Api\V1\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeePayrollProfileRequest extends FormRequest
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

        return ['payroll_status' => ['required', Rule::in(['active', 'inactive', 'hold'])], 'pay_frequency' => ['required', Rule::in(['monthly'])], 'base_salary' => ['required', 'integer', 'min:0', 'max:9007199254740991'], 'currency' => ['required', 'string', 'size:3'], 'effective_from' => ['required', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'], 'tax_identifier' => ['nullable', 'string', 'max:30'], 'statutory_registration' => ['nullable', 'array'], 'payment_financial_account_id' => ['nullable', 'uuid', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)], 'employee_bank_reference' => ['nullable', 'string', 'max:255'], 'components' => ['sometimes', 'array'], 'components.*.payroll_component_id' => ['required', 'uuid', 'distinct', Rule::exists('payroll_components', 'id')->where('company_id', $companyId)], 'components.*.fixed_amount' => ['nullable', 'integer', 'min:0', 'max:9007199254740991'], 'components.*.rate_bps' => ['nullable', 'integer', 'min:0', 'max:10000'], 'components.*.effective_from' => ['nullable', 'date'], 'components.*.effective_to' => ['nullable', 'date'], 'components.*.is_active' => ['sometimes', 'boolean']];
    }
}
