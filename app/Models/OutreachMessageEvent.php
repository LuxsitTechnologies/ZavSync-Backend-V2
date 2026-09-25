<?php

namespace App\Models;

use Database\Factories\OutreachMessageEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutreachMessageEvent extends Model
{
    /** @use HasFactory<OutreachMessageEventFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'message_id', 'provider_event_id', 'type', 'occurred_at', 'payload', 'processed_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'payload' => 'array', 'processed_at' => 'datetime'];
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
