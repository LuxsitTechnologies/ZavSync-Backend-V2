<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $ordered = $this->relationLoaded('lines') ? (int) $this->lines->sum('quantity_milli') : 0;
        $received = $this->relationLoaded('lines') ? (int) $this->lines->sum('received_quantity_milli') : 0;

        return [
            'id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'supplier_id' => (string) $this->supplier_id,
            'owner' => (string) $this->created_by,
            'number' => $this->number, 'po_number' => $this->number, 'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier->name),
            'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier->name), 'order_date' => $this->order_date->format('Y-m-d'),
            'date' => $this->order_date->format('Y-m-d'), 'expected_delivery_date' => $this->expected_delivery_date?->format('Y-m-d'),
            'expected_date' => $this->expected_delivery_date?->format('Y-m-d'), 'currency' => $this->currency, 'status' => $this->status->value,
            'reference' => $this->reference, 'notes' => $this->notes, 'subtotal' => $this->subtotal, 'discount' => $this->discount,
            'taxable_amount' => $this->taxable_amount, 'tax' => $this->tax, 'total' => $this->total,
            'received' => $ordered > 0 ? intdiv(($received * 100) + intdiv($ordered, 2), $ordered) : 0,
            'created_by' => (string) $this->created_by, 'submitted_by' => $this->submitted_by, 'submitted_at' => $this->submitted_at?->toISOString(),
            'approved_by' => $this->approved_by, 'approved_at' => $this->approved_at?->toISOString(), 'rejected_by' => $this->rejected_by,
            'rejected_at' => $this->rejected_at?->toISOString(), 'approval_note' => $this->approval_note,
            'cancelled_by' => $this->cancelled_by, 'cancelled_at' => $this->cancelled_at?->toISOString(), 'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => [
                'id' => (string) $line->id, 'position' => $line->position, 'item_id' => $line->item_id, 'item_name' => $line->item_name,
                'description' => $line->description, 'procurement_type' => $line->procurement_type, 'quantity_milli' => $line->quantity_milli,
                'received_quantity_milli' => $line->received_quantity_milli, 'billed_quantity_milli' => $line->billed_quantity_milli,
                'unit' => $line->unit, 'unit_price' => $line->unit_price, 'subtotal' => $line->subtotal, 'discount' => $line->discount,
                'taxable_amount' => $line->taxable_amount, 'tax_rate_bps' => $line->tax_rate_bps, 'tax_amount' => $line->tax_amount,
                'total' => $line->total, 'expense_account_id' => $line->expense_account_id, 'metadata' => $line->metadata,
            ])),
            'receipts' => PurchaseReceiptResource::collection($this->whenLoaded('receipts')),
            'bills' => SupplierBillResource::collection($this->whenLoaded('bills')),
        ];
    }
}
