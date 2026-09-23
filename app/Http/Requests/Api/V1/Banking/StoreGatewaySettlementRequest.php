<?php

namespace App\Http\Requests\Api\V1\Banking;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGatewaySettlementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'banking.settlements') === true;
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
            'provider' => ['required', 'string', 'max:80'],
            'settlement_reference' => ['required', 'string', 'max:120'],
            'settlement_date' => ['required', 'date'],
            'gross_amount' => ['required', 'integer', 'between:1,9007199254740991'],
            'fee_amount' => ['required', 'integer', 'between:0,9007199254740991'],
            'adjustment_amount' => ['nullable', 'integer', 'between:-9007199254740991,9007199254740991'],
            'net_amount' => ['required', 'integer', 'between:1,9007199254740991'],
            'currency' => ['required', 'string', 'size:3'],
            'destination_financial_account_id' => ['required', 'uuid', Rule::exists('financial_accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'clearing_account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('type', 'asset')->where('is_active', true))],
            'fee_account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereIn('type', ['expense', 'revenue'])->where('is_active', true))],
            'post' => ['nullable', 'boolean'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.source_type' => ['required_with:allocations', Rule::in(['customer_payment'])],
            'allocations.*.source_id' => ['required_with:allocations', 'uuid', 'distinct'],
            'allocations.*.amount' => ['required_with:allocations', 'integer', 'between:1,9007199254740991'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency' => strtoupper((string) $this->input('currency', 'PKR')), 'adjustment_amount' => $this->input('adjustment_amount', 0)]);
    }
}
