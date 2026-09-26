<?php

namespace App\Http\Requests\Api\V1\Ai;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIntelligenceScenarioRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'intelligence.scenarios.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $changeTypes = ['REVENUE_CHANGE', 'EXPENSE_CHANGE', 'INVENTORY_PURCHASE_CHANGE', 'PAYROLL_CHANGE'];

        return ['name' => ['required', 'string', 'max:255'], 'scenario_type' => ['required', Rule::in([...$changeTypes, 'COLLECTION_DELAY', 'CUSTOMER_NON_PAYMENT'])], 'assumptions' => ['required', 'array'], 'assumptions.change_bps' => [Rule::requiredIf(fn (): bool => in_array($this->input('scenario_type'), $changeTypes, true)), 'nullable', 'integer', 'between:-10000,100000'], 'assumptions.delay_days' => [Rule::requiredIf(fn (): bool => $this->input('scenario_type') === 'COLLECTION_DELAY'), 'nullable', 'integer', 'between:1,365'], 'assumptions.amount_minor' => [Rule::requiredIf(fn (): bool => $this->input('scenario_type') === 'CUSTOMER_NON_PAYMENT'), 'nullable', 'integer', 'between:0,9007199254740991']];
    }
}
