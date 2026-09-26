<?php

namespace App\Models;

use Database\Factories\AiMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiMessage extends Model
{
    /** @use HasFactory<AiMessageFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'ai_conversation_id', 'user_id', 'role', 'content', 'status', 'provider', 'model', 'input_tokens', 'output_tokens', 'cost_minor', 'metadata', 'idempotency_key'];

    protected function casts(): array
    {
        return ['content' => 'encrypted', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'cost_minor' => 'integer', 'metadata' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(AiMessageCitation::class);
    }

    public function toolRuns(): HasMany
    {
        return $this->hasMany(AiToolRun::class);
    }

    public function actionProposals(): HasMany
    {
        return $this->hasMany(AiActionProposal::class);
    }
}
