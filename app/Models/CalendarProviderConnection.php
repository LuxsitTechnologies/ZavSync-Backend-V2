<?php

namespace App\Models;

use Database\Factories\CalendarProviderConnectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarProviderConnection extends Model
{
    /** @use HasFactory<CalendarProviderConnectionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'user_id', 'provider', 'status', 'identity_email', 'access_token', 'refresh_token', 'token_expires_at', 'sync_metadata', 'last_synced_at', 'last_error_code', 'created_by', 'updated_by'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'sync_metadata' => 'encrypted:array', 'token_expires_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
