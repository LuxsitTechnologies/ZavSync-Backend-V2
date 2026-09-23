<?php

namespace App\Models;

use Database\Factories\ForecastLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForecastLine extends Model
{
    /** @use HasFactory<ForecastLineFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'forecast_id', 'account_id', 'accounting_period_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function forecast(): BelongsTo
    {
        return $this->belongsTo(Forecast::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }
}
