<?php

namespace App\Http\Requests\Api\V1\Planning;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBudgetRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'budget.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fiscal_year_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:255'], 'currency' => ['required', 'string', 'size:3'], 'description' => ['nullable', 'string'],
            'lines' => ['sometimes', 'array'], 'lines.*.account_id' => ['required', 'uuid'], 'lines.*.annual_amount' => ['nullable', 'integer'], 'lines.*.distribution' => ['nullable', 'in:equal,manual'],
            'lines.*.periods' => ['nullable', 'array'], 'lines.*.periods.*.period_id' => ['required_with:lines.*.periods', 'uuid'], 'lines.*.periods.*.amount' => ['required_with:lines.*.periods', 'integer'],
        ];
    }
}
