<?php

namespace App\Http\Requests\Api\V1\Fbr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePakistanFbrInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'pakistan_fbr.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');

        return [
            'customer_id' => ['nullable', 'uuid', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'invoice_type' => ['required', 'string', 'max:120'],
            'sale_type' => ['required', 'string', 'max:120'],
            'origin_province' => ['required', 'string', 'max:100'],
            'destination_province' => ['required', 'string', 'max:100'],
            'buyer_snapshot' => ['required', 'array:registration_number,name,type,province,address'],
            'buyer_snapshot.registration_number' => ['nullable', 'regex:/^(?:[0-9]{7}|[0-9]{13})$/'],
            'buyer_snapshot.name' => ['required', 'string', 'max:255'],
            'buyer_snapshot.type' => ['required', 'in:Registered,Unregistered'],
            'buyer_snapshot.province' => ['required', 'string', 'max:100'],
            'buyer_snapshot.address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.description' => ['required', 'string', 'max:2000'],
            'lines.*.hs_code' => ['required', 'string', 'max:60'],
            'lines.*.unit' => ['required', 'string', 'max:60'],
            'lines.*.quantity_milli' => ['required', 'integer', 'between:1,1000000000'],
            'lines.*.unit_price' => ['required', 'integer', 'between:0,9007199254740991'],
            'lines.*.discount' => ['sometimes', 'integer', 'between:0,9007199254740991'],
            'lines.*.tax_rate_bps' => ['required', 'integer', 'between:0,10000'],
            'lines.*.sales_tax' => ['nullable', 'integer', 'between:0,9007199254740991'],
            'lines.*.other_tax_rate_bps' => ['sometimes', 'integer', 'between:0,10000'],
            'lines.*.advance_tax_rate_bps' => ['sometimes', 'integer', 'between:0,10000'],
            'lines.*.withholding_tax_rate_bps' => ['sometimes', 'integer', 'between:0,10000'],
            'lines.*.extra_tax' => ['sometimes', 'integer', 'between:0,9007199254740991'],
            'lines.*.further_tax' => ['sometimes', 'integer', 'between:0,9007199254740991'],
            'lines.*.st_withheld' => ['sometimes', 'integer', 'between:0,9007199254740991'],
            'lines.*.fbr_rate_id' => ['required', 'string', 'max:120'],
            'lines.*.sro_schedule_id' => ['nullable', 'string', 'max:120'],
            'lines.*.sro_item_id' => ['nullable', 'string', 'max:120'],
        ];
    }
}
