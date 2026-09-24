<?php

namespace App\Http\Requests\Api\V1\Payroll;

use Illuminate\Validation\Rule;

class UpdatePayrollComponentRequest extends StorePayrollComponentRequest
{
    public function rules(): array
    {
        $rules = collect(parent::rules())->map(fn (array $fieldRules): array => array_map(fn ($rule) => $rule === 'required' ? 'sometimes' : $rule, $fieldRules))->all();
        $rules['code'] = ['sometimes', 'string', 'max:50', Rule::unique('payroll_components')->where('company_id', (string) $this->attributes->get('company_id'))->ignore((string) $this->route('component'))];

        return $rules;
    }
}
