<?php

namespace App\Http\Requests\Api\V1\Fbr;

use App\Models\FbrReferenceValue;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFbrConfigurationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $companyId = (string) $this->attributes->get('company_id');

        return $this->user()?->hasCompanyPermission($companyId, 'fbr.configuration.manage') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'seller_tax_identifier' => ['required', 'string', 'regex:/^(?:\d{7}|\d{13})$/'],
            'seller_business_name' => ['required', 'string', 'max:255'],
            'seller_province' => ['required', 'string', 'max:100', Rule::exists(FbrReferenceValue::class, 'code')->where(fn ($query) => $query->where('category', 'PROVINCE')->where('is_active', true))],
            'seller_address' => ['required', 'string', 'max:2000'],
            'environment' => ['required', Rule::in(['SANDBOX', 'PRODUCTION'])],
            'credential' => ['nullable', 'string', 'min:8', 'max:4000'],
        ];
    }
}
