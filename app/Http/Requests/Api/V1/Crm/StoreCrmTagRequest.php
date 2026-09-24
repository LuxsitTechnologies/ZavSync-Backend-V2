<?php

namespace App\Http\Requests\Api\V1\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmTagRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'crm.accounts.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');

        return ['name' => ['required', 'string', 'max:80'], 'normalized_name' => ['required', Rule::unique('crm_tags', 'normalized_name')->where('company_id', $companyId)->ignore($this->route('tag'))], 'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name')), 'normalized_name' => mb_strtolower(trim((string) $this->input('name')))]);
    }
}
