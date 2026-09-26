<?php

namespace App\Http\Requests\Api\V1\Ai;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKnowledgeSourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'ai.knowledge.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');

        return [
            'source_type' => ['required', Rule::in(['NOTE', 'DOCUMENT'])],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:1000000', 'required_if:source_type,NOTE'],
            'document_id' => ['nullable', 'uuid', 'required_if:source_type,DOCUMENT', Rule::exists('documents', 'id')->where('company_id', $companyId)],
            'access_permission' => ['required', 'string', 'max:120', Rule::exists('permissions', 'name')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['source_type' => mb_strtoupper((string) $this->input('source_type'))]);
    }
}
