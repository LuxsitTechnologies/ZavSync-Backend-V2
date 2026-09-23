<?php

namespace App\Http\Requests\Api\V1\Accounting;

class UpdateInvoiceRequest extends StoreInvoiceRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'accounting.edit') === true;
    }
}
