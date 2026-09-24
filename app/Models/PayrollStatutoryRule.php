<?php

namespace App\Models;

use Database\Factories\PayrollStatutoryRuleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollStatutoryRule extends Model
{
    /** @use HasFactory<PayrollStatutoryRuleFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'payroll_component_id', 'jurisdiction', 'rule_type', 'version', 'effective_from', 'effective_to', 'threshold_from', 'threshold_to', 'rate_bps', 'fixed_amount', 'minimum_amount', 'maximum_amount', 'metadata', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'threshold_from' => 'integer', 'threshold_to' => 'integer', 'rate_bps' => 'integer', 'fixed_amount' => 'integer', 'minimum_amount' => 'integer', 'maximum_amount' => 'integer', 'metadata' => 'array', 'is_active' => 'boolean'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class, 'payroll_component_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
