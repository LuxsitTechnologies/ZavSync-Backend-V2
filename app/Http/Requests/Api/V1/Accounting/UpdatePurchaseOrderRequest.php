<?php

namespace App\Http\Requests\Api\V1\Accounting;

class UpdatePurchaseOrderRequest extends StorePurchaseOrderRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'purchase_orders.update') === true;
    }
}
