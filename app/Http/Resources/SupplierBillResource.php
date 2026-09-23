<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierBillResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $displayStatus = $this->displayStatus();

        return [
            'id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'supplier_id' => (string) $this->supplier_id,
            'purchase_order_id' => $this->purchase_order_id, 'purchase_receipt_id' => $this->purchase_receipt_id,
            'bill_number' => $this->bill_number, 'number' => $this->bill_number, 'supplier_invoice_number' => $this->supplier_invoice_number,
            'reference' => $this->supplier_invoice_number, 'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier->name),
            'bill_date' => $this->bill_date->format('Y-m-d'), 'posting_date' => $this->posting_date->format('Y-m-d'),
            'due_date' => $this->due_date->format('Y-m-d'), 'currency' => $this->currency, 'status' => $displayStatus,
            'accounting_status' => $this->status->value, 'subtotal' => $this->subtotal, 'discount' => $this->discount,
            'taxable_amount' => $this->taxable_amount, 'purchase_tax' => $this->purchase_tax, 'tax' => $this->purchase_tax,
            'withholding_tax' => $this->withholding_tax, 'gross_total' => $this->gross_total, 'total' => $this->total,
            'amount_paid' => $this->amount_paid, 'paid' => $this->amount_paid, 'balance_due' => $this->balance_due,
            'outstanding' => $this->balance_due, 'days_overdue' => $displayStatus === 'overdue' ? $this->due_date->diffInDays(now()->startOfDay()) : 0,
            'notes' => $this->notes, 'journal_id' => $this->journal_id, 'reversal_journal_id' => $this->reversal_journal_id,
            'expense_account_id' => $this->relationLoaded('lines') ? $this->lines->first()?->expense_account_id : null,
            'expense_account_name' => $this->relationLoaded('lines') ? $this->lines->first()?->expenseAccount?->name : null,
            'created_by' => (string) $this->created_by, 'updated_by' => $this->updated_by, 'posted_by' => $this->posted_by,
            'posted_at' => $this->posted_at?->toISOString(), 'voided_by' => $this->voided_by, 'voided_at' => $this->voided_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
            'supplier' => $this->whenLoaded('supplier', fn () => new SupplierResource($this->supplier)),
            'purchase_order_number' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder?->number),
            'purchase_receipt_number' => $this->whenLoaded('purchaseReceipt', fn () => $this->purchaseReceipt?->number),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => [
                'id' => (string) $line->id, 'position' => $line->position, 'purchase_order_line_id' => $line->purchase_order_line_id,
                'purchase_receipt_line_id' => $line->purchase_receipt_line_id, 'item_id' => $line->item_id, 'item_name' => $line->item_name,
                'description' => $line->description, 'procurement_type' => $line->procurement_type, 'quantity_milli' => $line->quantity_milli,
                'unit' => $line->unit, 'unit_price' => $line->unit_price, 'subtotal' => $line->subtotal, 'discount' => $line->discount,
                'taxable_amount' => $line->taxable_amount, 'tax_rate_bps' => $line->tax_rate_bps, 'tax_amount' => $line->tax_amount,
                'withholding_rate_bps' => $line->withholding_rate_bps, 'withholding_amount' => $line->withholding_amount,
                'total' => $line->total, 'expense_account_id' => $line->expense_account_id, 'metadata' => $line->metadata,
            ])),
            'allocations' => $this->whenLoaded('allocations', fn () => SupplierPaymentResource::collection($this->allocations->pluck('payment'))),
        ];
    }
}
