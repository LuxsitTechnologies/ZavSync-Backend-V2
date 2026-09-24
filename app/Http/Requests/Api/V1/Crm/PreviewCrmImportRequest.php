<?php

namespace App\Http\Requests\Api\V1\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewCrmImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'crm.import') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['entity_type' => ['required', Rule::in(['ACCOUNT', 'CONTACT', 'LEAD'])], 'filename' => ['required', 'string', 'max:255', 'ends_with:.csv'], 'csv' => ['required', 'string', 'max:2097152'], 'mapping' => ['required', 'array', 'min:1'], 'mapping.*' => ['required', 'string', 'max:80']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['entity_type' => strtoupper((string) $this->input('entity_type'))]);
    }
}
