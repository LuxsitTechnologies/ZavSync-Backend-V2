<?php

namespace App\Http\Requests\Api\V1\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmContactRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'crm.contacts.manage') === true;
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
            'owner_id' => ['nullable', 'integer', Rule::exists('company_users', 'user_id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'first_name' => ['required', 'string', 'max:255'], 'last_name' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'], 'department' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'],
            'mobile' => ['nullable', 'string', 'max:40'], 'is_primary' => ['sometimes', 'boolean'],
            'address' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['status' => strtoupper((string) ($this->input('status') ?: 'ACTIVE')), 'is_primary' => $this->boolean('is_primary')]);
    }
}
