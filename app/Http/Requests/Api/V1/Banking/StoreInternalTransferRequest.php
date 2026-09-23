<?php

namespace App\Http\Requests\Api\V1\Banking;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInternalTransferRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'banking.transfer') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');
        $account = Rule::exists('financial_accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true));

        return [
            'source_financial_account_id' => ['required', 'uuid', $account],
            'destination_financial_account_id' => ['required', 'uuid', $account, 'different:source_financial_account_id'],
            'transfer_date' => ['required', 'date'],
            'amount' => ['required', 'integer', 'between:1,9007199254740991'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
