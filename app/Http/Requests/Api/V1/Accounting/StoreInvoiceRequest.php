<?php

namespace App\Http\Requests\Api\V1\Accounting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'accounting.create') === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');
        $money = ['integer', 'between:0,9007199254740991'];

        return [
            'customer_id' => ['required', 'uuid', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'invoice_date' => ['required', 'date'], 'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'currency' => ['required', 'string', 'size:3'], 'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'], 'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.item_id' => ['nullable', 'uuid', Rule::exists('inventory_items', 'id')->where('company_id', $companyId)], 'lines.*.item_name' => ['nullable', 'string', 'max:255'],
            'lines.*.description' => ['required', 'string', 'max:2000'],
            'lines.*.quantity_milli' => ['required', 'integer', 'between:1,1000000000'],
            'lines.*.unit' => ['required', 'string', 'max:30'], 'lines.*.unit_price' => ['required', ...$money],
            'lines.*.discount' => ['sometimes', ...$money], 'lines.*.tax_rate_bps' => ['sometimes', 'integer', 'between:0,10000'],
            'lines.*.other_tax_rate_bps' => ['sometimes', 'integer', 'between:0,10000'],
            'lines.*.advance_tax_rate_bps' => ['sometimes', 'integer', 'between:0,10000'],
            'lines.*.withholding_tax_rate_bps' => ['sometimes', 'integer', 'between:0,10000'],
            'lines.*.sales_type' => ['required', 'string', 'max:60'], 'lines.*.tax_metadata' => ['nullable', 'array'],
            'lines.*.tax_metadata.section_236g_applicable' => ['sometimes', 'boolean'],
            'lines.*.tax_metadata.section_236h_applicable' => ['sometimes', 'boolean'],
            'lines.*.tax_metadata.section_236g_status' => ['sometimes', 'nullable', 'string', 'max:100'],
            'lines.*.tax_metadata.section_236h_status' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency' => strtoupper((string) ($this->input('currency') ?: 'PKR'))]);
    }
}
