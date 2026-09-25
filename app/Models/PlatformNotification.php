<?php

namespace App\Models;

use Database\Factories\PlatformNotificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformNotification extends Model
{
    /** @use HasFactory<PlatformNotificationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'recipient_id', 'type', 'channel', 'title', 'message', 'related_type', 'related_id', 'related_url', 'delivery_state', 'metadata', 'read_at', 'delivered_at', 'failed_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'read_at' => 'datetime', 'delivered_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
