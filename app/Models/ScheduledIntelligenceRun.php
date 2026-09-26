<?php

namespace App\Models;

use Database\Factories\ScheduledIntelligenceRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledIntelligenceRun extends Model
{
    /** @use HasFactory<ScheduledIntelligenceRunFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'run_type', 'status', 'idempotency_key', 'metrics', 'failure_code', 'failure_message', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['metrics' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
