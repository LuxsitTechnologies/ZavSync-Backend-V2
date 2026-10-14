<?php

namespace App\Http\Requests\Api\V1\Fbr;

use Illuminate\Foundation\Http\FormRequest;

class StorePakistanFbrCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'pakistan_fbr.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'lines' => ['prohibited'],
            'taxable_amount' => ['prohibited'],
            'sales_tax' => ['prohibited'],
            'extra_tax' => ['prohibited'],
            'further_tax' => ['prohibited'],
            'st_withheld' => ['prohibited'],
            'total' => ['prohibited'],
        ];
    }
}
