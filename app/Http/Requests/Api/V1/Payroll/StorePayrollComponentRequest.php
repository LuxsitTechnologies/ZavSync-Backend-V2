<?php

namespace App\Http\Requests\Api\V1\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollComponentRequest extends FormRequest
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

        return ['code' => ['required', 'string', 'max:50', Rule::unique('payroll_components')->where('company_id', $companyId)], 'name' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::in(['EARNINGS', 'DEDUCTIONS', 'EMPLOYEE_CONTRIBUTIONS', 'EMPLOYER_CONTRIBUTIONS', 'TAX', 'REIMBURSEMENTS', 'OTHER'])], 'calculation_method' => ['required', Rule::in(['fixed', 'basis_points', 'statutory', 'manual'])], 'fixed_amount' => ['nullable', 'integer', 'min:0', 'max:9007199254740991'], 'rate_bps' => ['nullable', 'integer', 'min:0', 'max:10000'], 'calculation_base' => ['nullable', Rule::in(['basic', 'gross', 'taxable'])], 'is_taxable' => ['required', 'boolean'], 'is_active' => ['sometimes', 'boolean'], 'effective_from' => ['nullable', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'], 'gl_account_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('type', 'expense')->where('is_active', true))], 'liability_account_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('type', 'liability')->where('is_active', true))], 'description' => ['nullable', 'string', 'max:5000']];
    }
}
