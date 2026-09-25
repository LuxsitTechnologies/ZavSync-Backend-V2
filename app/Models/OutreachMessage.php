<?php

namespace App\Models;

use Database\Factories\OutreachMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutreachMessage extends Model
{
    /** @use HasFactory<OutreachMessageFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'enrollment_id', 'sequence_id', 'sequence_step_id', 'sending_identity_id', 'provider_connection_id', 'template_id', 'stable_message_id', 'provider_message_id', 'recipient_type', 'recipient_id', 'to_email', 'to_name', 'from_email', 'from_name', 'reply_to_email', 'subject', 'body_html', 'body_text', 'state', 'scheduled_at', 'queued_at', 'sending_at', 'sent_at', 'delivered_at', 'bounced_at', 'failed_at', 'cancelled_at', 'suppressed_at', 'replied_at', 'unsubscribe_token_hash', 'tracking_token_hash', 'failure_message'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'queued_at' => 'datetime', 'sending_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'bounced_at' => 'datetime', 'failed_at' => 'datetime', 'cancelled_at' => 'datetime', 'suppressed_at' => 'datetime', 'replied_at' => 'datetime'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(OutreachEnrollment::class, 'enrollment_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(OutreachSequence::class, 'sequence_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(OutreachSequenceStep::class, 'sequence_step_id');
    }

    public function sendingIdentity(): BelongsTo
    {
        return $this->belongsTo(EmailSendingIdentity::class, 'sending_identity_id');
    }

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(EmailProviderConnection::class, 'provider_connection_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(OutreachSendAttempt::class, 'message_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OutreachMessageEvent::class, 'message_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(OutreachMessageLink::class, 'message_id');
    }
}
