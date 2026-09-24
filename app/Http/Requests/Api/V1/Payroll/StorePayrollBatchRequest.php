<?php

namespace App\Http\Requests\Api\V1\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollBatchRequest extends FormRequest
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

        return ['payroll_period_id' => ['required', 'uuid', Rule::exists('payroll_periods', 'id')->where('company_id', $companyId)], 'accounting_date' => ['nullable', 'date'], 'correction_of_batch_id' => ['nullable', 'uuid', Rule::exists('payroll_batches', 'id')->where('company_id', $companyId)]];
    }
}
