<?php

namespace App\Models;

use Database\Factories\AnomalyResultFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnomalyResult extends Model
{
    /** @use HasFactory<AnomalyResultFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'category', 'source_module', 'metric', 'method', 'observed_value', 'expected_value', 'deviation_value', 'deviation_bps', 'threshold_bps', 'sample_size', 'window_start', 'window_end', 'evaluated_at', 'status', 'fingerprint', 'source_metrics', 'explanation'];

    protected function casts(): array
    {
        return ['observed_value' => 'integer', 'expected_value' => 'integer', 'deviation_value' => 'integer', 'deviation_bps' => 'integer', 'threshold_bps' => 'integer', 'sample_size' => 'integer', 'window_start' => 'date:Y-m-d', 'window_end' => 'date:Y-m-d', 'evaluated_at' => 'datetime', 'source_metrics' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
