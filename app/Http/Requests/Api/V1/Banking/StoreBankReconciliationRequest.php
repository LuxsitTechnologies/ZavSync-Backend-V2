<?php

namespace App\Http\Requests\Api\V1\Banking;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBankReconciliationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'banking.reconcile') === true;
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
            'bank_statement_import_id' => ['nullable', 'uuid', Rule::exists('bank_statement_imports', 'id')->where('company_id', $companyId)],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'statement_opening_balance' => ['required', 'integer', 'between:-9007199254740991,9007199254740991'],
            'statement_closing_balance' => ['required', 'integer', 'between:-9007199254740991,9007199254740991'],
        ];
    }
}
