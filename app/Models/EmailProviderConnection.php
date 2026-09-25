<?php

namespace App\Models;

use Database\Factories\EmailProviderConnectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailProviderConnection extends Model
{
    /** @use HasFactory<EmailProviderConnectionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'name', 'provider_type', 'status', 'configuration', 'credentials', 'access_token', 'refresh_token', 'token_expires_at', 'webhook_secret', 'last_verified_at', 'last_error', 'created_by', 'updated_by'];

    protected $hidden = ['configuration', 'credentials', 'access_token', 'refresh_token', 'webhook_secret'];

    protected function casts(): array
    {
        return ['configuration' => 'encrypted:array', 'credentials' => 'encrypted:array', 'access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'webhook_secret' => 'encrypted', 'token_expires_at' => 'datetime', 'last_verified_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function identities(): HasMany
    {
        return $this->hasMany(EmailSendingIdentity::class, 'provider_connection_id');
    }
}
