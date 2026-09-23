<?php

namespace App\Http\Requests\Api\V1\Accounting;

use App\Rules\PakistanCnic;
use App\Rules\PakistanNtn;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'accounting.create') === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');

        return [
            'name' => ['required', 'string', 'max:255'], 'legal_name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:business,individual,government'], 'ntn' => ['nullable', new PakistanNtn],
            'cnic' => ['nullable', new PakistanCnic], 'strn' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'],
            'billing_address' => ['nullable', 'string', 'max:2000'], 'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'], 'country' => ['required', 'string', 'size:2'],
            'postal_code' => ['nullable', 'string', 'max:20'], 'contact_person' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['required', 'integer', 'between:0,3650'],
            'credit_limit' => ['nullable', 'integer', 'between:0,9007199254740991'],
            'currency' => ['required', 'string', 'size:3'], 'tax_metadata' => ['nullable', 'array'],
            'is_active' => ['required', 'boolean'], 'notes' => ['nullable', 'string', 'max:5000'],
            'code' => ['sometimes', 'string', 'max:30', Rule::unique('customers')->where('company_id', $companyId)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ntn' => $this->digitsOrNull($this->input('ntn')), 'cnic' => $this->digitsOrNull($this->input('cnic')),
            'country' => strtoupper((string) ($this->input('country') ?: 'PK')),
            'currency' => strtoupper((string) ($this->input('currency') ?: 'PKR')),
            'type' => $this->input('type') ?: 'business', 'payment_terms_days' => $this->input('payment_terms_days', 30),
            'is_active' => $this->boolean('is_active', true),
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
