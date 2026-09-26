<?php

namespace App\Models;

use Database\Factories\AiMessageCitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessageCitation extends Model
{
    /** @use HasFactory<AiMessageCitationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'ai_message_id', 'knowledge_source_id', 'knowledge_chunk_id', 'ordinal', 'excerpt', 'locator'];

    protected function casts(): array
    {
        return ['ordinal' => 'integer', 'excerpt' => 'encrypted', 'locator' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class, 'ai_message_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'knowledge_source_id');
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeChunk::class, 'knowledge_chunk_id');
    }
}
