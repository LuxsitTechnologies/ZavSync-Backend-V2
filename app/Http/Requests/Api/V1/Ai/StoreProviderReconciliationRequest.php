<?php

namespace App\Http\Requests\Api\V1\Ai;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProviderReconciliationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'intelligence.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['provider' => ['required', Rule::in(['openai'])], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'provider_cost_minor' => ['required', 'integer', 'between:0,9007199254740991'], 'provider_reference' => ['nullable', 'string', 'max:255']];
    }
}
