<?php

namespace App\Models;

use Database\Factories\AiUsageRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageRecord extends Model
{
    /** @use HasFactory<AiUsageRecordFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'user_id', 'ai_conversation_id', 'ai_message_id', 'provider', 'model', 'operation', 'input_tokens', 'output_tokens', 'cost_minor', 'metadata', 'occurred_at'];

    protected function casts(): array
    {
        return ['input_tokens' => 'integer', 'output_tokens' => 'integer', 'cost_minor' => 'integer', 'metadata' => 'array', 'occurred_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
