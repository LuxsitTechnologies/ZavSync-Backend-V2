<?php

namespace App\Http\Requests\Api\V1\Outreach;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmailSendingIdentityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'outreach.providers.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return ['provider_connection_id' => [$required, 'uuid', Rule::exists('email_provider_connections', 'id')->where('company_id', $companyId)], 'from_email' => [$required, 'email:rfc', 'max:255'], 'from_name' => [$required, 'string', 'max:255'], 'reply_to_email' => ['nullable', 'email:rfc', 'max:255'], 'is_default' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean']];
    }
}
