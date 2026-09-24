<?php

namespace App\Models;

use Database\Factories\PayrollEntryLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollEntryLine extends Model
{
    /** @use HasFactory<PayrollEntryLineFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'payroll_entry_id', 'payroll_component_id', 'payroll_adjustment_id', 'statutory_rule_id', 'component_code', 'component_name', 'component_type', 'amount', 'is_taxable', 'gl_account_id', 'liability_account_id', 'calculation_snapshot'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'is_taxable' => 'boolean', 'calculation_snapshot' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class, 'payroll_entry_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class, 'payroll_component_id');
    }

    public function settlementAllocations(): HasMany
    {
        return $this->hasMany(PayrollLiabilitySettlementAllocation::class);
    }
}
