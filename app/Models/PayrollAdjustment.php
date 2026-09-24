<?php

namespace App\Models;

use Database\Factories\PayrollAdjustmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollAdjustment extends Model
{
    /** @use HasFactory<PayrollAdjustmentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'payroll_entry_id', 'payroll_component_id', 'amount', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
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
}
