<?php

namespace App\Http\Requests\Api\V1\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmDealRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'crm.deals.manage') === true;
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
            'account_id' => ['required', 'uuid', Rule::exists('crm_accounts', 'id')->where('company_id', $companyId)],
            'primary_contact_id' => ['nullable', 'uuid', Rule::exists('crm_contacts', 'id')->where('company_id', $companyId)],
            'lead_origin_id' => ['nullable', 'uuid', Rule::exists('crm_leads', 'id')->where('company_id', $companyId)],
            'pipeline_id' => ['required', 'uuid', Rule::exists('crm_pipelines', 'id')->where('company_id', $companyId)],
            'pipeline_stage_id' => ['required', 'uuid', Rule::exists('crm_pipeline_stages', 'id')->where('company_id', $companyId)],
            'owner_id' => ['nullable', 'integer', Rule::exists('company_users', 'user_id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'title' => ['required', 'string', 'max:255'], 'amount' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'currency' => ['required', 'string', 'size:3'], 'probability_bps' => ['nullable', 'integer', 'between:0,10000'],
            'expected_close_date' => ['nullable', 'date'], 'source' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:5000'], 'loss_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency' => strtoupper((string) ($this->input('currency') ?: 'PKR')), 'amount' => $this->input('amount', 0)]);
    }
}
