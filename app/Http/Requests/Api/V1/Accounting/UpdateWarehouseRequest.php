<?php

namespace App\Http\Requests\Api\V1\Accounting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateWarehouseRequest extends StoreWarehouseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return parent::authorize();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $companyId = (string) $this->attributes->get('company_id');
        $rules['code'] = ['required', 'string', 'max:40', Rule::unique('warehouses')->where('company_id', $companyId)->ignore((string) $this->route('warehouse'))];

        return $rules;
    }
}
