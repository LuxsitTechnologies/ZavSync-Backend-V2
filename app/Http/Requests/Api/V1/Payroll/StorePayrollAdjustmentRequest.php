<?php

namespace App\Http\Requests\Api\V1\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollAdjustmentRequest extends FormRequest
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

        return ['payroll_component_id' => ['required', 'uuid', Rule::exists('payroll_components', 'id')->where('company_id', $companyId)], 'amount' => ['required', 'integer', 'min:1', 'max:9007199254740991'], 'reason' => ['required', 'string', 'max:2000']];
    }
}
