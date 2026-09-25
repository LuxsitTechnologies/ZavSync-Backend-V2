<?php

namespace App\Models;

use Database\Factories\OutreachSendAttemptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutreachSendAttempt extends Model
{
    /** @use HasFactory<OutreachSendAttemptFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'message_id', 'attempt_number', 'idempotency_key', 'status', 'provider_message_id', 'provider_response', 'error_code', 'error_message', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['attempt_number' => 'integer', 'provider_response' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(OutreachMessage::class, 'message_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
