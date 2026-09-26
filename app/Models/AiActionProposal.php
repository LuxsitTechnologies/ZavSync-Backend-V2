<?php

namespace App\Models;

use Database\Factories\AiActionProposalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiActionProposal extends Model
{
    /** @use HasFactory<AiActionProposalFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'ai_conversation_id', 'ai_message_id', 'created_by', 'action_type', 'status', 'payload', 'impact_preview', 'required_permission', 'payload_checksum', 'idempotency_key', 'reviewed_by', 'reviewed_at', 'expires_at', 'executed_at', 'rejection_reason'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'impact_preview' => 'encrypted:array', 'reviewed_at' => 'datetime', 'expires_at' => 'datetime', 'executed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function execution(): HasOne
    {
        return $this->hasOne(AiActionExecution::class);
    }
}
