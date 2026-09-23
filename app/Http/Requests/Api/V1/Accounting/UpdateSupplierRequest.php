<?php

namespace App\Http\Requests\Api\V1\Accounting;

use App\Rules\PakistanCnic;
use App\Rules\PakistanNtn;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends StoreSupplierRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $companyId = (string) $this->attributes->get('company_id');
        $supplierId = $this->route('supplier');
        $rules['code'] = ['sometimes', 'string', 'max:30', Rule::unique('suppliers')->where('company_id', $companyId)->ignore($supplierId)];
        $rules['ntn'] = ['nullable', new PakistanNtn, Rule::unique('suppliers')->where('company_id', $companyId)->ignore($supplierId)];
        $rules['cnic'] = ['nullable', new PakistanCnic, Rule::unique('suppliers')->where('company_id', $companyId)->ignore($supplierId)];

        return $rules;
    }
}
