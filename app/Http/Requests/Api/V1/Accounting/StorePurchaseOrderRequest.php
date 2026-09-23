<?php

namespace App\Http\Requests\Api\V1\Accounting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'purchase_orders.create') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');
        $money = ['integer', 'between:0,9007199254740991'];

        return [
            'supplier_id' => ['required', 'uuid', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'order_date' => ['required', 'date'], 'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'currency' => ['required', 'string', 'size:3'], 'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'], 'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.item_id' => ['nullable', 'uuid', Rule::exists('inventory_items', 'id')->where('company_id', $companyId)], 'lines.*.item_name' => ['nullable', 'string', 'max:255'],
            'lines.*.description' => ['required', 'string', 'max:2000'], 'lines.*.procurement_type' => ['required', 'in:goods,service'],
            'lines.*.quantity_milli' => ['required', 'integer', 'between:1,1000000000'],
            'lines.*.unit' => ['required', 'string', 'max:30'], 'lines.*.unit_price' => ['required', ...$money],
            'lines.*.discount' => ['sometimes', ...$money], 'lines.*.tax_rate_bps' => ['sometimes', 'integer', 'between:0,10000'],
            'lines.*.expense_account_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)->whereIn('type', ['expense', 'asset']))],
            'lines.*.metadata' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency' => strtoupper((string) ($this->input('currency') ?: 'PKR'))]);
    }
}
