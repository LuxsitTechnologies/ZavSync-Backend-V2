<?php

namespace App\Http\Requests\Api\V1\Outreach;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OutreachSequenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'outreach.sequences.manage') === true;
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

        return ['name' => [$required, 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'sending_identity_id' => [$required, 'uuid', Rule::exists('email_sending_identities', 'id')->where('company_id', $companyId)], 'owner_id' => ['nullable', 'integer', Rule::exists('company_users', 'user_id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))], 'timezone' => [$required, 'timezone'], 'starts_at' => ['nullable', 'date'], 'allowed_weekdays' => [$required, 'array', 'min:1', 'max:7'], 'allowed_weekdays.*' => ['integer', 'between:1,7', 'distinct'], 'send_window_start' => [$required, 'date_format:H:i'], 'send_window_end' => [$required, 'date_format:H:i', 'after:send_window_start'], 'track_opens' => ['sometimes', 'boolean'], 'track_clicks' => ['sometimes', 'boolean'], 'stop_on_reply' => ['sometimes', 'boolean'], 'steps' => [$required, 'array', 'min:1', 'max:50'], 'steps.*.type' => ['required', Rule::in(['EMAIL', 'WAIT'])], 'steps.*.template_id' => ['nullable', 'uuid', Rule::exists('email_templates', 'id')->where('company_id', $companyId)], 'steps.*.subject' => ['nullable', 'string', 'max:998'], 'steps.*.body_text' => ['nullable', 'string', 'max:100000'], 'steps.*.body_html' => ['nullable', 'string', 'max:200000'], 'steps.*.wait_minutes' => ['required', 'integer', 'between:0,525600']];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('steps')) {
            $this->merge(['steps' => collect($this->input('steps'))->map(fn ($step) => [...$step, 'type' => mb_strtoupper((string) ($step['type'] ?? ''))])->all()]);
        }
    }
}
