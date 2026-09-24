<?php

namespace App\Http\Requests\Api\V1\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionCrmLeadRequest extends FormRequest
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
        return ['status' => ['required', Rule::in(['NEW', 'CONTACTED', 'QUALIFIED', 'UNQUALIFIED', 'LOST'])], 'qualification_notes' => ['nullable', 'string', 'max:5000']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['status' => strtoupper((string) $this->input('status'))]);
    }
}
