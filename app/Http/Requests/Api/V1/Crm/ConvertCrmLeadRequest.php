<?php

namespace App\Http\Requests\Api\V1\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConvertCrmLeadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'crm.leads.manage') === true;
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
            'idempotency_key' => ['required', 'string', 'max:100'], 'create_account' => ['required', 'boolean'],
            'create_contact' => ['required', 'boolean'], 'create_deal' => ['required', 'boolean'],
            'account_id' => ['nullable', 'uuid', Rule::exists('crm_accounts', 'id')->where('company_id', $companyId)],
            'contact_id' => ['nullable', 'uuid', Rule::exists('crm_contacts', 'id')->where('company_id', $companyId)],
            'pipeline_id' => ['nullable', 'uuid', Rule::exists('crm_pipelines', 'id')->where('company_id', $companyId)],
            'pipeline_stage_id' => ['nullable', 'uuid', Rule::exists('crm_pipeline_stages', 'id')->where('company_id', $companyId)],
            'deal_title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
