<?php

namespace App\Models;

use Database\Factories\CrmScoreEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CrmScoreEvent extends Model
{
    /** @use HasFactory<CrmScoreEventFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'scoreable_type', 'scoreable_id', 'score_rule_id', 'points', 'reason', 'details', 'calculated_at', 'created_by'];

    protected function casts(): array
    {
        return ['points' => 'integer', 'details' => 'array', 'calculated_at' => 'datetime'];
    }

    public function scoreable(): MorphTo
    {
        return $this->morphTo();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CrmScoreRule::class, 'score_rule_id');
    }
}
