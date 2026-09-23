<?php

namespace App\Models;

use Database\Factories\FiscalYearFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    /** @use HasFactory<FiscalYearFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'name', 'start_date', 'end_date', 'currency', 'status', 'created_by', 'closed_by', 'closed_at', 'reopened_by', 'reopened_at', 'reopen_reason'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'closed_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class)->orderBy('start_date');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(Forecast::class);
    }

    public function closeRecords(): HasMany
    {
        return $this->hasMany(AccountingCloseRecord::class);
    }
}
