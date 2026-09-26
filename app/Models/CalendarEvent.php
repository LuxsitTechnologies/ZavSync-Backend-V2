<?php

namespace App\Models;

use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEvent extends Model
{
    /** @use HasFactory<CalendarEventFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'calendar_provider_connection_id', 'external_event_id', 'title', 'description', 'starts_at', 'ends_at', 'status', 'attendees', 'sync_metadata', 'etag', 'synced_at'];

    protected function casts(): array
    {
        return ['attendees' => 'encrypted:array', 'sync_metadata' => 'encrypted:array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'synced_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CalendarProviderConnection::class, 'calendar_provider_connection_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
