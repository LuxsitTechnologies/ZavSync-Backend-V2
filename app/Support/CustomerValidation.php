<?php

namespace App\Support;

use App\Rules\PakistanCnic;
use App\Rules\PakistanNtn;
use Illuminate\Validation\Rule;

class CustomerValidation
{
    /** @return array<string, mixed> */
    public static function rules(string $companyId, string $required = 'required', bool $includeCode = true): array
    {
        $rules = [
            'name' => [$required, 'string', 'max:255'], 'legal_name' => ['nullable', 'string', 'max:255'],
            'type' => [$required, 'in:business,individual,government'], 'ntn' => ['nullable', new PakistanNtn],
            'cnic' => ['nullable', new PakistanCnic], 'strn' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'],
            'billing_address' => ['nullable', 'string', 'max:2000'], 'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'], 'country' => [$required, 'string', 'size:2'],
            'postal_code' => ['nullable', 'string', 'max:20'], 'contact_person' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => [$required, 'integer', 'between:0,3650'],
            'credit_limit' => ['nullable', 'integer', 'between:0,9007199254740991'],
            'currency' => [$required, 'string', 'size:3'], 'tax_metadata' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'], 'notes' => ['nullable', 'string', 'max:5000'],
        ];
        if ($includeCode) {
            $rules['code'] = ['sometimes', 'string', 'max:30', Rule::unique('customers')->where('company_id', $companyId)];
        }

        return $rules;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public static function normalized(array $input): array
    {
        $digits = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? preg_replace('/\D+/', '', $value) : null;

        return [
            'ntn' => $digits($input['ntn'] ?? null), 'cnic' => $digits($input['cnic'] ?? null),
            'country' => strtoupper((string) ($input['country'] ?? 'PK')), 'currency' => strtoupper((string) ($input['currency'] ?? 'PKR')),
            'type' => strtolower((string) ($input['type'] ?? 'business')), 'payment_terms_days' => $input['payment_terms_days'] ?? 30,
            'is_active' => filter_var($input['is_active'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }
}
