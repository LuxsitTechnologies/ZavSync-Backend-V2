<?php

namespace App\Models;

use Database\Factories\InternalTransferFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalTransfer extends Model
{
    /** @use HasFactory<InternalTransferFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'sequence', 'number', 'source_financial_account_id', 'destination_financial_account_id', 'transfer_date', 'amount', 'currency', 'reference', 'notes', 'journal_id', 'idempotency_key', 'idempotency_hash', 'created_by'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'transfer_date' => 'date:Y-m-d', 'amount' => 'integer'];
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'source_financial_account_id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'destination_financial_account_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
