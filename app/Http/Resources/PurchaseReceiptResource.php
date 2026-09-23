<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReceiptResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'purchase_order_id' => (string) $this->purchase_order_id,
            'supplier_id' => (string) $this->supplier_id, 'number' => $this->number, 'receipt_date' => $this->receipt_date->format('Y-m-d'),
            'status' => $this->status->value, 'notes' => $this->notes, 'received_by' => (string) $this->received_by,
            'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier->name),
            'purchase_order_number' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder->number),
            'created_at' => $this->created_at?->toISOString(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => [
                'id' => (string) $line->id, 'purchase_order_line_id' => (string) $line->purchase_order_line_id,
                'ordered_quantity_milli' => $line->ordered_quantity_milli, 'previously_received_quantity_milli' => $line->previously_received_quantity_milli,
                'quantity_received_milli' => $line->quantity_received_milli, 'remaining_quantity_milli' => $line->remaining_quantity_milli,
            ])),
        ];
    }
}
