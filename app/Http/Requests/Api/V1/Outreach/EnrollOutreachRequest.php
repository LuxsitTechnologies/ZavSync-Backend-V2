<?php

namespace App\Http\Requests\Api\V1\Outreach;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnrollOutreachRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'outreach.send') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['recipient_type' => ['required', Rule::in(['CONTACT', 'LEAD'])], 'recipient_ids' => ['nullable', 'array', 'max:1000', 'required_without:filters'], 'recipient_ids.*' => ['uuid', 'distinct'], 'filters' => ['nullable', 'array', 'required_without:recipient_ids'], 'filters.owner_id' => ['nullable', 'integer'], 'filters.account_id' => ['nullable', 'uuid'], 'filters.status' => ['nullable', 'string', 'max:30'], 'filters.source' => ['nullable', 'string', 'max:100'], 'filters.tag_id' => ['nullable', 'uuid'], 'filters.deal_id' => ['nullable', 'uuid'], 'filters.min_score' => ['nullable', 'integer', 'between:0,100'], 'idempotency_key' => ['required', 'string', 'max:180']];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('recipient_type')) {
            $this->merge(['recipient_type' => mb_strtoupper((string) $this->input('recipient_type'))]);
        }
    }
}
