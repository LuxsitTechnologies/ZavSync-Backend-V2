<?php

namespace App\Models;

use Database\Factories\PayrollComponentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollComponent extends Model
{
    /** @use HasFactory<PayrollComponentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'code', 'name', 'type', 'calculation_method', 'fixed_amount', 'rate_bps', 'calculation_base', 'is_taxable', 'is_active', 'effective_from', 'effective_to', 'gl_account_id', 'liability_account_id', 'description', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['fixed_amount' => 'integer', 'rate_bps' => 'integer', 'is_taxable' => 'boolean', 'is_active' => 'boolean', 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }

    public function liabilityAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'liability_account_id');
    }

    public function statutoryRules(): HasMany
    {
        return $this->hasMany(PayrollStatutoryRule::class);
    }
}
