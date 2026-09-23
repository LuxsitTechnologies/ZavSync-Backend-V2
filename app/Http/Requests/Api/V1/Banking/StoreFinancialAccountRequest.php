<?php

namespace App\Http\Requests\Api\V1\Banking;

use App\Enums\FinancialAccountType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'banking.manage') === true;
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
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(FinancialAccountType::class)],
            'bank_name' => ['nullable', 'string', 'max:255', 'required_if:type,bank'],
            'account_title' => ['nullable', 'string', 'max:255'],
            'masked_account_number' => ['nullable', 'string', 'max:80'],
            'iban' => ['nullable', 'string', 'max:40', Rule::unique('financial_accounts')->where('company_id', $companyId)],
            'currency' => ['required', 'string', 'size:3'],
            'gl_account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('type', 'asset')->where('is_active', true)), Rule::unique('financial_accounts')->where('company_id', $companyId)],
            'opening_balance' => ['nullable', 'integer', 'between:-9007199254740991,9007199254740991'],
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'currency' => strtoupper((string) $this->input('currency', 'PKR')),
            'iban' => $this->filled('iban') ? strtoupper(str_replace(' ', '', (string) $this->input('iban'))) : null,
            'is_default' => $this->boolean('is_default'),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
        ]);
    }
}
