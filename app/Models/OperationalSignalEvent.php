<?php

namespace App\Models;

use Database\Factories\OperationalSignalEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalSignalEvent extends Model
{
    /** @use HasFactory<OperationalSignalEventFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'operational_priority_signal_id', 'actor_id', 'event_type', 'from_status', 'to_status', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(OperationalPrioritySignal::class, 'operational_priority_signal_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
