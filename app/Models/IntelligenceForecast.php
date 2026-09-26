<?php

namespace App\Models;

use Database\Factories\IntelligenceForecastFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceForecast extends Model
{
    /** @use HasFactory<IntelligenceForecastFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'metric', 'source_module', 'method', 'horizon_days', 'status', 'source_data', 'assumptions', 'projection_points', 'confidence_bps', 'limitations', 'generated_at', 'fingerprint'];

    protected function casts(): array
    {
        return ['horizon_days' => 'integer', 'source_data' => 'array', 'assumptions' => 'array', 'projection_points' => 'array', 'confidence_bps' => 'integer', 'generated_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
