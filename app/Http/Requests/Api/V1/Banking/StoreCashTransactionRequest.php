<?php

namespace App\Http\Requests\Api\V1\Banking;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'banking.post') === true;
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
            'financial_account_id' => ['required', 'uuid', Rule::exists('financial_accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('type', 'cash')->where('is_active', true))],
            'direction' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => ['required', 'integer', 'between:1,9007199254740991'],
            'transaction_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:255'],
            'counterpart_account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
        ];
    }
}
