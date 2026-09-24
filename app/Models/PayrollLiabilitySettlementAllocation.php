<?php

namespace App\Models;

use Database\Factories\PayrollLiabilitySettlementAllocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLiabilitySettlementAllocation extends Model
{
    /** @use HasFactory<PayrollLiabilitySettlementAllocationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'payroll_liability_settlement_id', 'payroll_entry_line_id', 'amount'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(PayrollLiabilitySettlement::class, 'payroll_liability_settlement_id');
    }

    public function entryLine(): BelongsTo
    {
        return $this->belongsTo(PayrollEntryLine::class, 'payroll_entry_line_id');
    }
}
