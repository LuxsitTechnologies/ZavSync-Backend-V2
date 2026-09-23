<?php

namespace App\Http\Requests\Api\V1\Banking;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateFinancialAccountRequest extends StoreFinancialAccountRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return parent::authorize();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $companyId = (string) $this->attributes->get('company_id');
        $accountId = (string) $this->route('financial_account');
        $rules['iban'] = ['nullable', 'string', 'max:40', Rule::unique('financial_accounts')->where('company_id', $companyId)->ignore($accountId)];
        $rules['gl_account_id'] = ['required', 'uuid', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('type', 'asset')->where('is_active', true)), Rule::unique('financial_accounts')->where('company_id', $companyId)->ignore($accountId)];

        return $rules;
    }
}
