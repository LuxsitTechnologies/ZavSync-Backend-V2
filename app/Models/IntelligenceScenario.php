<?php

namespace App\Models;

use Database\Factories\IntelligenceScenarioFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceScenario extends Model
{
    /** @use HasFactory<IntelligenceScenarioFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'created_by', 'name', 'scenario_type', 'status', 'assumptions', 'baseline', 'scenario', 'delta', 'idempotency_key', 'idempotency_hash', 'calculated_at'];

    protected function casts(): array
    {
        return ['assumptions' => 'encrypted:array', 'baseline' => 'encrypted:array', 'scenario' => 'encrypted:array', 'delta' => 'encrypted:array', 'calculated_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
