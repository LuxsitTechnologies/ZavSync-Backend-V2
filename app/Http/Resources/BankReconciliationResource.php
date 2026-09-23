<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankReconciliationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'number' => $this->number, 'financial_account_id' => $this->financial_account_id, 'financial_account' => $this->whenLoaded('financialAccount'), 'bank_statement_import_id' => $this->bank_statement_import_id, 'period_start' => $this->period_start?->toDateString(), 'period_end' => $this->period_end?->toDateString(), 'statement_opening_balance' => $this->statement_opening_balance, 'statement_closing_balance' => $this->statement_closing_balance, 'book_balance' => $this->book_balance, 'difference' => $this->difference, 'status' => $this->status, 'matches' => $this->whenLoaded('matches'), 'completed_at' => $this->completed_at, 'reopened_at' => $this->reopened_at, 'reopen_reason' => $this->reopen_reason, 'created_at' => $this->created_at];
    }
}
