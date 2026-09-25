<?php

namespace App\Http\Requests\Api\V1\Outreach;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmailProviderConnectionRequest extends FormRequest
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
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return ['name' => [$required, 'string', 'max:255'], 'provider_type' => [$required, Rule::in(['SMTP', 'GOOGLE', 'MICROSOFT'])], 'configuration' => [$required, 'array'], 'configuration.host' => ['required_if:provider_type,SMTP', 'string', 'max:255'], 'configuration.port' => ['required_if:provider_type,SMTP', 'integer', 'between:1,65535'], 'configuration.encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])], 'configuration.username' => ['nullable', 'string', 'max:255'], 'credentials' => ['nullable', 'array'], 'credentials.password' => ['nullable', 'string', 'max:2000'], 'access_token' => ['nullable', 'string', 'max:10000'], 'refresh_token' => ['nullable', 'string', 'max:10000'], 'token_expires_at' => ['nullable', 'date'], 'webhook_secret' => ['nullable', 'string', 'max:2000']];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('provider_type')) {
            $this->merge(['provider_type' => mb_strtoupper((string) $this->input('provider_type'))]);
        }
    }
}
