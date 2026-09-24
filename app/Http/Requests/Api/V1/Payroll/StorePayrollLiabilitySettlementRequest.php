<?php

namespace App\Http\Requests\Api\V1\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollLiabilitySettlementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'payroll.settle-liabilities') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');

        return ['payroll_batch_id' => ['required', 'uuid', Rule::exists('payroll_batches', 'id')->where('company_id', $companyId)], 'liability_type' => ['required', Rule::in(['TAX', 'EMPLOYEE_CONTRIBUTION', 'EMPLOYER_CONTRIBUTION', 'OTHER_DEDUCTION'])], 'financial_account_id' => ['required', 'uuid', Rule::exists('financial_accounts', 'id')->where('company_id', $companyId)], 'payment_date' => ['required', 'date'], 'amount' => ['required', 'integer', 'min:1', 'max:9007199254740991'], 'reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:5000']];
    }
}
