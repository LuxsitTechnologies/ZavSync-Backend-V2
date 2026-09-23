<?php

namespace App\Http\Requests\Api\V1\Accounting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FinancialReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'accounting.view') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $comparative = $this->routeIs('*.comparative');

        return ['from' => [$comparative ? 'required' : 'nullable', 'date'], 'to' => [$comparative ? 'required' : 'nullable', 'date', 'after_or_equal:from'], 'as_of' => ['nullable', 'date'], 'comparison_from' => [$comparative ? 'required' : 'nullable', 'date'], 'comparison_to' => [$comparative ? 'required' : 'nullable', 'date', 'after_or_equal:comparison_from']];
    }
}
