<?php

namespace App\Http\Requests\Api\V1\Crm;

use App\Rules\PakistanCnic;
use App\Rules\PakistanNtn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'crm.accounts.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');

        return [
            'name' => ['required', 'string', 'max:255'], 'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'url:http,https', 'max:255'], 'ntn' => ['nullable', new PakistanNtn],
            'cnic' => ['nullable', new PakistanCnic], 'registration_number' => ['nullable', 'string', 'max:80'],
            'industry' => ['nullable', 'string', 'max:255'], 'account_type' => ['required', Rule::in(['BUSINESS', 'INDIVIDUAL', 'GOVERNMENT', 'PARTNER'])],
            'address' => ['nullable', 'string', 'max:2000'], 'city' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'size:2'], 'postal_code' => ['nullable', 'string', 'max:20'],
            'owner_id' => ['nullable', 'integer', Rule::exists('company_users', 'user_id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'source' => ['nullable', 'string', 'max:80'], 'status' => ['required', Rule::in(['PROSPECT', 'ACTIVE', 'CUSTOMER', 'INACTIVE'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ntn' => $this->digitsOrNull($this->input('ntn')), 'cnic' => $this->digitsOrNull($this->input('cnic')),
            'country' => strtoupper((string) ($this->input('country') ?: 'PK')),
            'account_type' => strtoupper((string) ($this->input('account_type') ?: 'BUSINESS')),
            'status' => strtoupper((string) ($this->input('status') ?: 'PROSPECT')),
        ]);
    }

    private function digitsOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? preg_replace('/\D+/', '', $value) : null;
    }
}
