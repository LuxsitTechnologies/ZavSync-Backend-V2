<?php

namespace App\Models;

use Database\Factories\InternalCalendarEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalCalendarEvent extends Model
{
    /** @use HasFactory<InternalCalendarEventFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'organizer_user_id', 'title', 'description', 'starts_at', 'ends_at', 'timezone', 'status', 'version', 'cancelled_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime', 'version' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_user_id');
    }
}
