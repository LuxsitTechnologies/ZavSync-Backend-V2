<?php

namespace App\Http\Requests\Api\V1\Accounting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryActionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $permission = match ((string) $this->route()?->getName()) {
            'inventory.transfer' => 'inventory.transfer',
            'inventory.adjustment', 'inventory.cost-adjustment' => 'inventory.adjust',
            'inventory.customer-return', 'inventory.supplier-return' => 'inventory.return',
            default => 'inventory.manage',
        };

        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), $permission) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (string) $this->attributes->get('company_id');
        $warehouse = Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true));
        $item = Rule::exists('inventory_items', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true)->where('track_inventory', true));

        return [
            'item_id' => ['sometimes', 'required', 'uuid', $item], 'warehouse_id' => ['sometimes', 'required', 'uuid', $warehouse],
            'source_warehouse_id' => ['sometimes', 'required', 'uuid', $warehouse], 'destination_warehouse_id' => ['sometimes', 'required', 'uuid', $warehouse, 'different:source_warehouse_id'],
            'quantity_milli' => ['sometimes', 'required', 'integer', 'between:1,1000000000'],
            'unit_cost' => ['sometimes', 'nullable', 'integer', 'between:0,9000000000'],
            'direction' => ['sometimes', 'required', Rule::in(['positive', 'negative'])],
            'transaction_date' => ['required', 'date'], 'reason' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'], 'reference' => ['nullable', 'string', 'max:255'],
            'invoice_id' => ['sometimes', 'required', 'uuid', Rule::exists('invoices', 'id')->where('company_id', $companyId)],
            'purchase_receipt_id' => ['sometimes', 'required', 'uuid', Rule::exists('purchase_receipts', 'id')->where('company_id', $companyId)],
            'lines' => ['sometimes', 'required', 'array', 'min:1'], 'lines.*.source_line_id' => ['required_with:lines', 'uuid', 'distinct'],
            'lines.*.quantity_milli' => ['required_with:lines', 'integer', 'between:1,1000000000'],
            'layer_id' => ['sometimes', 'required', 'uuid', Rule::exists('inventory_layers', 'id')->where('company_id', $companyId)],
            'amount' => ['sometimes', 'required', 'integer', 'not_in:0', 'between:-9007199254740991,9007199254740991'],
        ];
    }
}
