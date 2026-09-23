<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancialAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'type' => $this->type->value, 'bank_name' => $this->bank_name, 'account_title' => $this->account_title, 'masked_account_number' => $this->masked_account_number, 'iban' => $this->iban, 'currency' => $this->currency, 'gl_account_id' => $this->gl_account_id, 'gl_account' => $this->whenLoaded('glAccount'), 'opening_balance' => $this->opening_balance, 'is_default' => $this->is_default, 'is_active' => $this->is_active, 'notes' => $this->notes, 'book_balance' => $this->when(isset($this->book_balance), $this->book_balance), 'statement_balance' => $this->when(isset($this->statement_balance), $this->statement_balance), 'created_at' => $this->created_at, 'updated_at' => $this->updated_at];
    }
}
