<?php

namespace App\Models;

use Database\Factories\KnowledgeChunkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends Model
{
    /** @use HasFactory<KnowledgeChunkFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'knowledge_source_id', 'source_version', 'chunk_index', 'content', 'content_hash', 'locator', 'embedding'];

    protected $hidden = ['content', 'embedding'];

    protected function casts(): array
    {
        return ['source_version' => 'integer', 'chunk_index' => 'integer', 'content' => 'encrypted', 'locator' => 'array', 'embedding' => 'encrypted:array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'knowledge_source_id');
    }
}
