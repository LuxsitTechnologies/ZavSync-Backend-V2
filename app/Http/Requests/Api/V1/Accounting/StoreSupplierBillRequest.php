<?php

namespace App\Http\Requests\Api\V1\Accounting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierBillRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'supplier_bills.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');
        $supplierId = (string) $this->input('supplier_id');
        $money = ['integer', 'between:0,9007199254740991'];

        return [
            'supplier_id' => ['required', 'uuid', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'purchase_order_id' => ['nullable', 'uuid', Rule::exists('purchase_orders', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('supplier_id', $supplierId))],
            'purchase_receipt_id' => ['nullable', 'uuid', Rule::exists('purchase_receipts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('supplier_id', $supplierId))],
            'supplier_invoice_number' => ['required', 'string', 'max:100', Rule::unique('supplier_bills')->where(fn ($query) => $query->where('company_id', $companyId)->where('supplier_id', $supplierId))],
            'bill_date' => ['required', 'date'], 'posting_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:bill_date'], 'currency' => ['required', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:5000'], 'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.purchase_order_line_id' => ['nullable', 'uuid', 'distinct'], 'lines.*.purchase_receipt_line_id' => ['nullable', 'uuid', 'distinct'],
            'lines.*.item_id' => ['nullable', 'uuid'], 'lines.*.item_name' => ['nullable', 'string', 'max:255'],
            'lines.*.description' => ['required', 'string', 'max:2000'], 'lines.*.procurement_type' => ['required', 'in:goods,service'],
            'lines.*.quantity_milli' => ['required', 'integer', 'between:1,9007199254740991'],
            'lines.*.unit' => ['required', 'string', 'max:30'], 'lines.*.unit_price' => ['required', ...$money],
            'lines.*.discount' => ['sometimes', ...$money], 'lines.*.tax_rate_bps' => ['sometimes', 'integer', 'between:0,10000'],
            'lines.*.withholding_rate_bps' => ['sometimes', 'integer', 'between:0,10000'],
            'lines.*.expense_account_id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)->whereIn('type', ['expense', 'asset']))],
            'lines.*.metadata' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'posting_date' => $this->input('posting_date', $this->input('bill_date')),
            'currency' => strtoupper((string) ($this->input('currency') ?: 'PKR')),
        ]);
    }
}
