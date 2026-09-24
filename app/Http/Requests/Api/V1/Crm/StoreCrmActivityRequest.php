<?php

namespace App\Http\Requests\Api\V1\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmActivityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'crm.activities.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');
        $tables = ['account' => 'crm_accounts', 'contact' => 'crm_contacts', 'lead' => 'crm_leads', 'deal' => 'crm_deals'];
        $relatedType = strtolower((string) $this->input('related_type'));

        return [
            'related_type' => ['nullable', Rule::in(array_keys($tables))],
            'related_id' => ['nullable', 'uuid', 'required_with:related_type', isset($tables[$relatedType]) ? Rule::exists($tables[$relatedType], 'id')->where('company_id', $companyId) : Rule::in([])],
            'owner_id' => ['nullable', 'integer', Rule::exists('company_users', 'user_id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'type' => ['required', Rule::in(['CALL', 'MEETING', 'EMAIL', 'TASK', 'NOTE'])], 'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'], 'due_at' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(['PENDING', 'COMPLETED', 'CANCELLED'])],
            'priority' => ['required', Rule::in(['LOW', 'MEDIUM', 'HIGH'])], 'outcome' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['type' => strtoupper((string) $this->input('type')), 'status' => strtoupper((string) ($this->input('status') ?: 'PENDING')), 'priority' => strtoupper((string) ($this->input('priority') ?: 'MEDIUM'))]);
    }
}
