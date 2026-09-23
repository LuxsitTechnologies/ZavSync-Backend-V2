<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerPaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'number' => $this->number,
            'party_id' => (string) $this->customer_id, 'party_name' => $this->whenLoaded('customer', fn () => $this->customer->name),
            'document_id' => (string) $this->invoice_id, 'document_number' => $this->whenLoaded('invoice', fn () => $this->invoice->invoice_number),
            'payment_date' => $this->payment_date->format('Y-m-d'), 'amount' => $this->amount, 'method' => $this->method->value,
            'bank_account_id' => (string) $this->bank_account_id, 'reference' => $this->reference ?? '', 'note' => $this->notes,
            'journal_id' => (string) $this->journal_id, 'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
