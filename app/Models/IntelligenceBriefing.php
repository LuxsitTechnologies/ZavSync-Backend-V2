<?php

namespace App\Models;

use Database\Factories\IntelligenceBriefingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceBriefing extends Model
{
    /** @use HasFactory<IntelligenceBriefingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'created_by', 'period', 'period_start', 'period_end', 'status', 'structured_data', 'narrative', 'provider', 'model', 'enrichment_error_code', 'enrichment_attempted_at', 'fingerprint', 'generated_at'];

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'structured_data' => 'encrypted:array', 'narrative' => 'encrypted', 'enrichment_attempted_at' => 'datetime', 'generated_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
