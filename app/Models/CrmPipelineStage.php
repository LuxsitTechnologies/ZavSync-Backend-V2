<?php

namespace App\Models;

use Database\Factories\CrmPipelineStageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmPipelineStage extends Model
{
    /** @use HasFactory<CrmPipelineStageFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'pipeline_id', 'name', 'position', 'probability_bps', 'is_won', 'is_lost', 'is_active'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'probability_bps' => 'integer', 'is_won' => 'boolean', 'is_lost' => 'boolean', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(CrmPipeline::class, 'pipeline_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(CrmDeal::class, 'pipeline_stage_id');
    }
}
