<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'financial_account_id' => $this->financial_account_id, 'bank_statement_import_id' => $this->bank_statement_import_id, 'statement_row' => $this->statement_row, 'evidence_type' => $this->evidence_type, 'transaction_date' => $this->transaction_date?->toDateString(), 'value_date' => $this->value_date?->toDateString(), 'description' => $this->description, 'bank_reference' => $this->bank_reference, 'external_transaction_id' => $this->external_transaction_id, 'direction' => $this->direction->value, 'amount' => $this->amount, 'running_balance' => $this->running_balance, 'currency' => $this->currency, 'counterparty_name' => $this->counterparty_name, 'counterparty_account' => $this->counterparty_account, 'status' => $this->status->value, 'classification_journal_id' => $this->classification_journal_id, 'matches' => $this->whenLoaded('matches'), 'created_at' => $this->created_at];
    }
}
