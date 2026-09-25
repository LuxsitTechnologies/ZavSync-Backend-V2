<?php

namespace App\Models;

use Database\Factories\OutreachSequenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutreachSequence extends Model
{
    /** @use HasFactory<OutreachSequenceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'sending_identity_id', 'owner_id', 'name', 'description', 'status', 'timezone', 'allowed_weekdays', 'send_window_start', 'send_window_end', 'track_opens', 'track_clicks', 'stop_on_reply', 'starts_at', 'activated_at', 'paused_at', 'completed_at', 'archived_at', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['allowed_weekdays' => 'array', 'track_opens' => 'boolean', 'track_clicks' => 'boolean', 'stop_on_reply' => 'boolean', 'starts_at' => 'datetime', 'activated_at' => 'datetime', 'paused_at' => 'datetime', 'completed_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sendingIdentity(): BelongsTo
    {
        return $this->belongsTo(EmailSendingIdentity::class, 'sending_identity_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(OutreachSequenceStep::class, 'sequence_id')->orderBy('position');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(OutreachEnrollment::class, 'sequence_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OutreachMessage::class, 'sequence_id');
    }
}
