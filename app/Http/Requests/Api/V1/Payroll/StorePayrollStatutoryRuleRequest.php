<?php

namespace App\Http\Requests\Api\V1\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollStatutoryRuleRequest extends FormRequest
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

        return ['payroll_component_id' => ['required', 'uuid', Rule::exists('payroll_components', 'id')->where('company_id', $companyId)], 'jurisdiction' => ['required', 'string', 'max:80'], 'rule_type' => ['required', 'string', 'max:50'], 'version' => ['required', 'string', 'max:40'], 'effective_from' => ['required', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'], 'threshold_from' => ['sometimes', 'integer', 'min:0', 'max:9007199254740991'], 'threshold_to' => ['nullable', 'integer', 'min:0', 'max:9007199254740991'], 'rate_bps' => ['sometimes', 'integer', 'min:0', 'max:10000'], 'fixed_amount' => ['sometimes', 'integer', 'min:0', 'max:9007199254740991'], 'minimum_amount' => ['nullable', 'integer', 'min:0', 'max:9007199254740991'], 'maximum_amount' => ['nullable', 'integer', 'min:0', 'max:9007199254740991'], 'metadata' => ['nullable', 'array'], 'is_active' => ['sometimes', 'boolean']];
    }
}
