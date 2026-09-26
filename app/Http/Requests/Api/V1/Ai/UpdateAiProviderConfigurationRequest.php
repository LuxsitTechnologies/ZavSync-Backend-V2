<?php

namespace App\Http\Requests\Api\V1\Ai;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiProviderConfigurationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'ai.providers.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['provider' => ['required', 'in:openai'], 'chat_model' => ['required', 'string', 'max:100'], 'embedding_model' => ['required', 'string', 'max:100'], 'api_key' => ['nullable', 'string', 'max:10000'], 'settings' => ['nullable', 'array:organization,project,timeout_seconds,max_output_tokens,temperature,input_cost_per_million_minor,output_cost_per_million_minor,embedding_cost_per_million_minor'], 'settings.organization' => ['nullable', 'string', 'max:200'], 'settings.project' => ['nullable', 'string', 'max:200'], 'settings.timeout_seconds' => ['nullable', 'integer', 'between:5,120'], 'settings.max_output_tokens' => ['nullable', 'integer', 'between:64,32768'], 'settings.temperature' => ['nullable', 'numeric', 'between:0,2'], 'settings.input_cost_per_million_minor' => ['nullable', 'integer', 'between:0,1000000000'], 'settings.output_cost_per_million_minor' => ['nullable', 'integer', 'between:0,1000000000'], 'settings.embedding_cost_per_million_minor' => ['nullable', 'integer', 'between:0,1000000000'], 'is_enabled' => ['required', 'boolean']];
    }
}
