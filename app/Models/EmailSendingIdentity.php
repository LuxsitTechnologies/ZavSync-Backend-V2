<?php

namespace App\Models;

use Database\Factories\EmailSendingIdentityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailSendingIdentity extends Model
{
    /** @use HasFactory<EmailSendingIdentityFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'provider_connection_id', 'from_email', 'from_name', 'reply_to_email', 'verification_status', 'is_default', 'is_active', 'verified_at', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean', 'verified_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(EmailProviderConnection::class, 'provider_connection_id');
    }

    public function sequences(): HasMany
    {
        return $this->hasMany(OutreachSequence::class, 'sending_identity_id');
    }
}
