<?php

namespace App\Http\Requests\Api\V1\Accounting;

use App\Models\PurchaseOrder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseReceiptRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasCompanyPermission((string) $this->attributes->get('company_id'), 'purchase_orders.receive') === true;
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
            'receipt_date' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:5000'],
            'warehouse_id' => ['nullable', 'uuid', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'uuid', 'distinct', Rule::exists('purchase_order_lines', 'id')->where(fn ($query) => $query->whereIn('purchase_order_id', PurchaseOrder::query()->where('company_id', $companyId)->select('id')))],
            'lines.*.quantity_received_milli' => ['required', 'integer', 'between:1,1000000000'],
        ];
    }
}
