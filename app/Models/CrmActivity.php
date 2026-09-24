<?php

namespace App\Models;

use Database\Factories\CrmActivityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CrmActivity extends Model
{
    /** @use HasFactory<CrmActivityFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'activityable_type', 'activityable_id', 'owner_id', 'type', 'subject', 'description', 'due_at', 'completed_at', 'status', 'priority', 'outcome', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function activityable(): MorphTo
    {
        return $this->morphTo();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
