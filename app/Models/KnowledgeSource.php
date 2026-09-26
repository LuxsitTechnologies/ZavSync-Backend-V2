<?php

namespace App\Models;

use Database\Factories\KnowledgeSourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KnowledgeSource extends Model
{
    /** @use HasFactory<KnowledgeSourceFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['company_id', 'document_id', 'source_type', 'title', 'content', 'access_permission', 'status', 'checksum_sha256', 'version', 'chunk_count', 'metadata', 'indexed_at', 'failed_at', 'created_by', 'updated_by'];

    protected $hidden = ['content'];

    protected function casts(): array
    {
        return ['content' => 'encrypted', 'version' => 'integer', 'chunk_count' => 'integer', 'metadata' => 'array', 'indexed_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function ingestionRuns(): HasMany
    {
        return $this->hasMany(KnowledgeIngestionRun::class);
    }
}
