<?php

namespace App\Http\Requests\Api\V1\Accounting;

use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends StoreCustomerRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'accounting.edit') === true;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['code'] = ['sometimes', 'string', 'max:30', Rule::unique('customers')->where('company_id', (string) $this->attributes->get('company_id'))->ignore($this->route('customer'))];

        return $rules;
    }
}
