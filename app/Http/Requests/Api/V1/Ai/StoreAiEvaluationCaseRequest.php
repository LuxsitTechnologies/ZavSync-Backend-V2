<?php

namespace App\Http\Requests\Api\V1\Ai;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAiEvaluationCaseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'ai.evaluations.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'prompt' => ['required', 'string', 'max:20000'], 'expected_citations' => ['nullable', 'array', 'max:20'], 'expected_citations.*' => ['string', 'max:255'], 'expected_tools' => ['nullable', 'array', 'max:20'], 'expected_tools.*' => ['string', 'max:100'], 'forbidden_actions' => ['nullable', 'array', 'max:20'], 'forbidden_actions.*' => ['string', 'max:80'], 'is_active' => ['sometimes', 'boolean']];
    }
}
