<?php

namespace App\Http\Requests\Api\V1\Accounting;

use App\Rules\PakistanCnic;
use App\Rules\PakistanNtn;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'suppliers.manage') === true;
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
            'name' => ['required', 'string', 'max:255'], 'legal_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'], 'billing_address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:255'], 'province' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'size:2'], 'postal_code' => ['nullable', 'string', 'max:20'],
            'ntn' => ['nullable', new PakistanNtn, Rule::unique('suppliers')->where('company_id', $companyId)],
            'cnic' => ['nullable', new PakistanCnic, Rule::unique('suppliers')->where('company_id', $companyId)],
            'strn' => ['nullable', 'string', 'max:30'], 'tax_status' => ['nullable', 'string', 'max:40'],
            'payment_terms_days' => ['required', 'integer', 'between:0,3650'], 'currency' => ['required', 'string', 'size:3'],
            'default_expense_account_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)->whereIn('type', ['expense', 'asset']))],
            'default_payable_account_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)->where('type', 'liability'))],
            'is_active' => ['required', 'boolean'], 'notes' => ['nullable', 'string', 'max:5000'],
            'code' => ['sometimes', 'string', 'max:30', Rule::unique('suppliers')->where('company_id', $companyId)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ntn' => $this->digitsOrNull($this->input('ntn', $this->input('tax_number'))),
            'cnic' => $this->digitsOrNull($this->input('cnic')),
            'billing_address' => $this->input('billing_address', $this->input('address')),
            'country' => strtoupper((string) ($this->input('country') ?: 'PK')),
            'currency' => strtoupper((string) ($this->input('currency') ?: 'PKR')),
            'payment_terms_days' => $this->input('payment_terms_days', 30),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : $this->input('status', 'active') === 'active',
        ]);
    }

    private function digitsOrNull(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return preg_replace('/\D+/', '', $value);
    }
}
