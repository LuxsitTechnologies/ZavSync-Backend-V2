<?php

namespace App\Models;

use Database\Factories\AiProviderReconciliationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProviderReconciliation extends Model
{
    /** @use HasFactory<AiProviderReconciliationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'imported_by', 'provider', 'period_start', 'period_end', 'internal_cost_minor', 'provider_cost_minor', 'difference_minor', 'status', 'provider_reference', 'reconciled_at'];

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'internal_cost_minor' => 'integer', 'provider_cost_minor' => 'integer', 'difference_minor' => 'integer', 'reconciled_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
