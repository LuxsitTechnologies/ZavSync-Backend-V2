<?php

namespace App\Models;

use Database\Factories\ForecastFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Forecast extends Model
{
    /** @use HasFactory<ForecastFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'fiscal_year_id', 'based_on_budget_id', 'based_on_forecast_id', 'name', 'version', 'status', 'currency', 'actuals_through', 'description', 'is_active', 'created_by', 'activated_by', 'activated_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_active' => 'boolean', 'actuals_through' => 'date:Y-m-d', 'activated_at' => 'datetime'];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function basedOnBudget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'based_on_budget_id');
    }

    public function basedOnForecast(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_forecast_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ForecastLine::class);
    }
}
