<?php

namespace App\Models;

use Database\Factories\KnowledgeIngestionRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeIngestionRun extends Model
{
    /** @use HasFactory<KnowledgeIngestionRunFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'knowledge_source_id', 'idempotency_key', 'source_version', 'status', 'attempt', 'chunk_count', 'failure_code', 'failure_message', 'requested_by', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['source_version' => 'integer', 'attempt' => 'integer', 'chunk_count' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
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
