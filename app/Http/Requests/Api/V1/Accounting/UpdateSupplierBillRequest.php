<?php

namespace App\Http\Requests\Api\V1\Accounting;

use Illuminate\Validation\Rule;

class UpdateSupplierBillRequest extends StoreSupplierBillRequest
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
        $supplierId = (string) $this->input('supplier_id');
        $rules['supplier_invoice_number'] = ['required', 'string', 'max:100', Rule::unique('supplier_bills')->where(fn ($query) => $query->where('company_id', $companyId)->where('supplier_id', $supplierId))->ignore($this->route('bill'))];

        return $rules;
    }
}
