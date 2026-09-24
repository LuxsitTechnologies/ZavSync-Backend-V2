<?php

namespace App\Http\Requests\Api\V1\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmLeadRequest extends FormRequest
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
            'account_id' => ['nullable', 'uuid', Rule::exists('crm_accounts', 'id')->where('company_id', $companyId)],
            'contact_id' => ['nullable', 'uuid', Rule::exists('crm_contacts', 'id')->where('company_id', $companyId)],
            'owner_id' => ['nullable', 'integer', Rule::exists('company_users', 'user_id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'first_name' => ['required', 'string', 'max:255'], 'last_name' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'], 'job_title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'],
            'mobile' => ['nullable', 'string', 'max:40'], 'website' => ['nullable', 'url:http,https', 'max:255'],
            'source' => ['nullable', 'string', 'max:80'], 'status' => ['required', Rule::in(['NEW', 'CONTACTED', 'QUALIFIED', 'UNQUALIFIED', 'LOST'])],
            'estimated_value' => ['required', 'integer', 'min:0', 'max:9007199254740991'], 'currency' => ['required', 'string', 'size:3'],
            'expected_timeframe' => ['nullable', 'date'], 'interest' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'], 'qualification_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['status' => strtoupper((string) ($this->input('status') ?: 'NEW')), 'currency' => strtoupper((string) ($this->input('currency') ?: 'PKR')), 'estimated_value' => $this->input('estimated_value', 0)]);
    }
}
