<?php

namespace App\Models;

use Database\Factories\CompanyInvitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyInvitation extends Model
{
    /** @use HasFactory<CompanyInvitationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'email', 'token_hash', 'status', 'role_ids', 'invited_by', 'expires_at', 'accepted_at', 'revoked_at', 'emailed_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['role_ids' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime', 'emailed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
