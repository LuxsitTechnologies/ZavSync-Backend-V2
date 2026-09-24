<?php

namespace App\Models;

use Database\Factories\PayrollLiabilitySettlementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollLiabilitySettlement extends Model
{
    /** @use HasFactory<PayrollLiabilitySettlementFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'sequence', 'number', 'payroll_batch_id', 'liability_type', 'financial_account_id', 'payment_date', 'amount', 'currency', 'reference', 'notes', 'journal_id', 'idempotency_key', 'idempotency_hash', 'created_by'];

    protected function casts(): array
    {
        return ['payment_date' => 'date:Y-m-d', 'amount' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayrollBatch::class, 'payroll_batch_id');
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PayrollLiabilitySettlementAllocation::class);
    }
}
