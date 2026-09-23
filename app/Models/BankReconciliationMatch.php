<?php

namespace App\Models;

use Database\Factories\BankReconciliationMatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationMatch extends Model
{
    /** @use HasFactory<BankReconciliationMatchFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'bank_transaction_id', 'bank_reconciliation_id', 'matchable_type', 'matchable_id', 'amount', 'confidence', 'reason', 'status', 'idempotency_key', 'idempotency_hash', 'matched_by', 'matched_at', 'unmatched_by', 'unmatched_at', 'unmatch_reason'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'matched_at' => 'datetime', 'unmatched_at' => 'datetime'];
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }
}
