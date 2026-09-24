<?php

namespace App\Http\Requests\Api\V1\Accounting;

use App\Support\CustomerValidation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'accounting.create') === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return CustomerValidation::rules((string) $this->attributes->get('company_id'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CustomerValidation::normalized($this->all()));
    }
}
