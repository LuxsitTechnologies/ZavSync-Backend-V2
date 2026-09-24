<?php

namespace App\Http\Requests\Api\V1\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollPeriodRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'payroll.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');

        return ['fiscal_year_id' => ['nullable', 'uuid', Rule::exists('fiscal_years', 'id')->where('company_id', $companyId)], 'accounting_period_id' => ['nullable', 'uuid', Rule::exists('accounting_periods', 'id')->where('company_id', $companyId)], 'name' => ['required', 'string', 'max:255'], 'frequency' => ['required', Rule::in(['monthly'])], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'pay_date' => ['required', 'date', 'after_or_equal:period_start']];
    }
}
