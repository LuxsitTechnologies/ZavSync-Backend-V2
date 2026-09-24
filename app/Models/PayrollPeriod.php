<?php

namespace App\Models;

use Database\Factories\PayrollPeriodFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    /** @use HasFactory<PayrollPeriodFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'fiscal_year_id', 'accounting_period_id', 'name', 'frequency', 'period_start', 'period_end', 'pay_date', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'pay_date' => 'date:Y-m-d'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(PayrollBatch::class);
    }
}
