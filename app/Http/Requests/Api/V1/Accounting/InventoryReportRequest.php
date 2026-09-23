<?php

namespace App\Http\Requests\Api\V1\Accounting;

use App\Enums\InventoryMovementType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $permission = str_contains((string) $this->route()?->getName(), 'valuation') || str_contains((string) $this->route()?->getName(), 'reconciliation') ? 'inventory.valuation' : 'inventory.view';

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

        return [
            'item_id' => ['nullable', 'uuid', Rule::exists('inventory_items', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'uuid', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'as_of' => ['nullable', 'date'], 'type' => ['nullable', Rule::enum(InventoryMovementType::class)],
            'category' => ['nullable', 'string', 'max:255'], 'search' => ['nullable', 'string', 'max:255'],
        ];
    }
}
