<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $displayStatus = $this->displayStatus();

        return [
            'id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'customer_id' => (string) $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer->name),
            'client_name' => $this->whenLoaded('customer', fn () => $this->customer->name),
            'invoice_number' => $this->invoice_number, 'invoice_date' => $this->invoice_date->format('Y-m-d'),
            'issue_date' => $this->invoice_date->format('Y-m-d'), 'due_date' => $this->due_date->format('Y-m-d'), 'currency' => $this->currency,
            'status' => $displayStatus, 'payment_status' => $displayStatus, 'accounting_status' => $this->status->value,
            'subtotal' => $this->subtotal, 'discount' => $this->discount, 'taxable_amount' => $this->taxable_amount,
            'tax' => $this->sales_tax, 'sales_tax' => $this->sales_tax, 'other_tax' => $this->other_tax,
            'advance_tax' => $this->advance_tax, 'withholding_tax' => $this->withholding_tax, 'total' => $this->total,
            'paid_amount' => $this->amount_paid, 'amount_paid' => $this->amount_paid,
            'outstanding' => $this->balance_due, 'balance' => $this->balance_due, 'balance_due' => $this->balance_due,
            'days_overdue' => $displayStatus === 'overdue' ? $this->due_date->diffInDays(now()->startOfDay()) : 0,
            'notes' => $this->notes, 'terms' => $this->terms, 'fbr_status' => $this->fbr_status->value,
            'fbr_reference_number' => $this->fbr_reference_number, 'fbr_response_metadata' => $this->fbr_response_metadata,
            'journal_id' => $this->journal_id, 'reversal_journal_id' => $this->reversal_journal_id,
            'posted_by' => $this->posted_by, 'posted_at' => $this->posted_at?->toISOString(),
            'created_by' => (string) $this->created_by, 'created_at' => $this->created_at?->toISOString(),
            'updated_by' => $this->updated_by, 'updated_at' => $this->updated_at?->toISOString(),
            'customer' => $this->whenLoaded('customer', fn () => new CustomerResource($this->customer)),
            'company' => $this->whenLoaded('company', fn () => ['id' => (string) $this->company->id, 'name' => $this->company->name, 'currency' => $this->company->currency]),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => [
                'id' => (string) $line->id, 'position' => $line->position, 'item_id' => $line->item_id, 'item_name' => $line->item_name,
                'description' => $line->description, 'quantity_milli' => $line->quantity_milli, 'unit' => $line->unit,
                'unit_price' => $line->unit_price, 'subtotal' => $line->subtotal, 'discount' => $line->discount,
                'taxable_amount' => $line->taxable_amount, 'tax_rate_bps' => $line->tax_rate_bps, 'tax_amount' => $line->tax_amount,
                'other_tax_rate_bps' => $line->other_tax_rate_bps, 'other_tax_amount' => $line->other_tax_amount,
                'advance_tax_rate_bps' => $line->advance_tax_rate_bps, 'advance_tax_amount' => $line->advance_tax_amount,
                'withholding_tax_rate_bps' => $line->withholding_tax_rate_bps, 'withholding_tax_amount' => $line->withholding_tax_amount,
                'total' => $line->total, 'sales_type' => $line->sales_type, 'tax_metadata' => $line->tax_metadata,
            ])),
        ];
    }
}
