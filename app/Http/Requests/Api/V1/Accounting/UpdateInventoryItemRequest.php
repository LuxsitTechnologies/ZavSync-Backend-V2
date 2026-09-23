<?php

namespace App\Http\Requests\Api\V1\Accounting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateInventoryItemRequest extends StoreInventoryItemRequest
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
        $itemId = (string) $this->route('item');
        $rules['sku'] = ['required', 'string', 'max:80', Rule::unique('inventory_items')->where('company_id', $companyId)->ignore($itemId)];
        $rules['barcode'] = ['nullable', 'string', 'max:100', Rule::unique('inventory_items')->where('company_id', $companyId)->ignore($itemId)];

        return $rules;
    }
}
