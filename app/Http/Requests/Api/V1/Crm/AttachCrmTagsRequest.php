<?php

namespace App\Http\Requests\Api\V1\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachCrmTagsRequest extends FormRequest
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

        return ['entity_type' => ['required', Rule::in(['account', 'contact', 'lead', 'deal'])], 'entity_id' => ['required', 'uuid'], 'tag_ids' => ['required', 'array'], 'tag_ids.*' => ['uuid', 'distinct', Rule::exists('crm_tags', 'id')->where('company_id', $companyId)]];
    }
}
