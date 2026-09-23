<?php

namespace App\Http\Requests\Api\V1\Planning;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateForecastRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'forecast.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['name' => ['sometimes', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'actuals_through' => ['nullable', 'date'], 'lines' => ['sometimes', 'array'], 'lines.*.account_id' => ['required', 'uuid'], 'lines.*.period_id' => ['required', 'uuid'], 'lines.*.amount' => ['required', 'integer']];
    }
}
