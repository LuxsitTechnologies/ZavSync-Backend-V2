<?php

namespace App\Http\Requests\Api\V1\Accounting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'inventory.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');
        $account = fn (array $types) => Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)->whereIn('type', $types));

        return [
            'sku' => ['required', 'string', 'max:80', Rule::unique('inventory_items')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', Rule::in(['inventory', 'non_inventory', 'service'])], 'track_inventory' => ['required', 'boolean'],
            'unit' => ['required', 'string', 'max:30'], 'sales_unit' => ['nullable', 'string', 'max:30'],
            'purchase_unit' => ['nullable', 'string', 'max:30'], 'category' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('inventory_items')->where('company_id', $companyId)],
            'is_active' => ['required', 'boolean'], 'sales_price' => ['required', 'integer', 'between:0,9000000000'],
            'default_purchase_cost' => ['required', 'integer', 'between:0,9000000000'],
            'reorder_level_milli' => ['required', 'integer', 'between:0,1000000000'],
            'reorder_quantity_milli' => ['required', 'integer', 'between:0,1000000000'],
            'inventory_asset_account_id' => ['required_if:type,inventory', 'nullable', 'uuid', $account(['asset'])],
            'cogs_account_id' => ['required_if:type,inventory', 'nullable', 'uuid', $account(['expense'])],
            'sales_account_id' => ['nullable', 'uuid', $account(['revenue'])],
            'inventory_adjustment_account_id' => ['required_if:type,inventory', 'nullable', 'uuid', $account(['expense', 'revenue'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $type = (string) $this->input('type', 'inventory');
        $this->merge([
            'sku' => strtoupper(trim((string) $this->input('sku'))), 'type' => $type,
            'track_inventory' => $type === 'inventory' && ($this->has('track_inventory') ? $this->boolean('track_inventory') : true),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
            'unit' => (string) $this->input('unit', 'unit'), 'sales_price' => $this->input('sales_price', 0),
            'default_purchase_cost' => $this->input('default_purchase_cost', 0),
            'reorder_level_milli' => $this->input('reorder_level_milli', 0),
            'reorder_quantity_milli' => $this->input('reorder_quantity_milli', 0),
        ]);
    }
}
