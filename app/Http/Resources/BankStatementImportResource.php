<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankStatementImportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'financial_account_id' => $this->financial_account_id, 'financial_account' => $this->whenLoaded('financialAccount'), 'original_filename' => $this->original_filename, 'statement_reference' => $this->statement_reference, 'statement_start_date' => $this->statement_start_date?->toDateString(), 'statement_end_date' => $this->statement_end_date?->toDateString(), 'opening_balance' => $this->opening_balance, 'closing_balance' => $this->closing_balance, 'status' => $this->status, 'column_mapping' => $this->column_mapping, 'preview_rows' => $this->preview_rows, 'row_count' => $this->row_count, 'imported_count' => $this->imported_count, 'duplicate_count' => $this->duplicate_count, 'transactions' => BankTransactionResource::collection($this->whenLoaded('transactions')), 'confirmed_at' => $this->confirmed_at, 'created_at' => $this->created_at];
    }
}
