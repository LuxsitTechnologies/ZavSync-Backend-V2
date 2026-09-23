<?php

namespace App\Models;

use Database\Factories\GatewaySettlementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GatewaySettlement extends Model
{
    /** @use HasFactory<GatewaySettlementFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'provider', 'settlement_reference', 'settlement_date', 'gross_amount', 'fee_amount', 'adjustment_amount', 'net_amount', 'currency', 'destination_financial_account_id', 'clearing_account_id', 'fee_account_id', 'status', 'journal_id', 'idempotency_key', 'idempotency_hash', 'created_by', 'posted_by', 'posted_at'];

    protected function casts(): array
    {
        return ['settlement_date' => 'date:Y-m-d', 'gross_amount' => 'integer', 'fee_amount' => 'integer', 'adjustment_amount' => 'integer', 'net_amount' => 'integer', 'posted_at' => 'datetime'];
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'destination_financial_account_id');
    }

    public function clearingAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'clearing_account_id');
    }

    public function feeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'fee_account_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(GatewaySettlementAllocation::class);
    }
}
