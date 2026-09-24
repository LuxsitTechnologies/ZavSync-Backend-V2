<?php

namespace App\Http\Requests\Api\V1\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmScoreRuleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'crm.scoring.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('crm_score_rules')->where('company_id', $companyId)->where('target_type', $this->input('target_type'))->ignore($this->route('rule'))], 'target_type' => ['required', Rule::in(['LEAD', 'DEAL'])],
            'field' => ['required', Rule::in(['source', 'email', 'phone', 'company_name', 'estimated_value', 'status', 'activity_count', 'days_inactive', 'amount', 'stage'])],
            'operator' => ['required', Rule::in(['EQUALS', 'NOT_EMPTY', 'GREATER_OR_EQUAL', 'LESS_OR_EQUAL'])],
            'comparison_value' => ['nullable', 'string', 'max:255'], 'points' => ['required', 'integer', 'between:-1000,1000'],
            'position' => ['required', 'integer', 'between:0,1000'], 'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
