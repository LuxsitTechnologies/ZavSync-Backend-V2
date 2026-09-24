<?php

namespace App\Http\Requests\Api\V1\Crm;

class UpdateCrmDealRequest extends StoreCrmDealRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'crm.deals.manage') === true;
    }
}
