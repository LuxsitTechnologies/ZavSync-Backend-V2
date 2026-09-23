<?php

namespace App\Http\Requests\Api\V1\Banking;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewBankStatementImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'banking.import') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');

        return [
            'financial_account_id' => ['required', 'uuid', Rule::exists('financial_accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('type', 'bank')->where('is_active', true))],
            'file' => ['required', 'file', 'mimes:csv,txt', 'extensions:csv', 'max:5120'],
            'statement_reference' => ['nullable', 'string', 'max:255'],
            'statement_start_date' => ['nullable', 'date'],
            'statement_end_date' => ['nullable', 'date', 'after_or_equal:statement_start_date'],
            'opening_balance' => ['nullable', 'integer', 'between:-9007199254740991,9007199254740991'],
            'closing_balance' => ['nullable', 'integer', 'between:-9007199254740991,9007199254740991'],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:100'],
        ];
    }
}
