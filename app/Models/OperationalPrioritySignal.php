<?php

namespace App\Models;

use Database\Factories\OperationalPrioritySignalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationalPrioritySignal extends Model
{
    /** @use HasFactory<OperationalPrioritySignalFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'category', 'source_module', 'source_type', 'source_id', 'title', 'description', 'severity', 'priority_score', 'confidence_bps', 'status', 'fingerprint', 'supporting_metrics', 'score_breakdown', 'explanation_metadata', 'related_url', 'assigned_user_id', 'detected_at', 'effective_at', 'due_at', 'last_seen_at', 'acknowledged_by', 'acknowledged_at', 'resolved_by', 'resolved_at', 'dismissed_by', 'dismissed_at', 'resolution_note'];

    protected function casts(): array
    {
        return ['priority_score' => 'integer', 'confidence_bps' => 'integer', 'supporting_metrics' => 'array', 'score_breakdown' => 'array', 'explanation_metadata' => 'array', 'detected_at' => 'datetime', 'effective_at' => 'datetime', 'due_at' => 'datetime', 'last_seen_at' => 'datetime', 'acknowledged_at' => 'datetime', 'resolved_at' => 'datetime', 'dismissed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OperationalSignalEvent::class);
    }
}
