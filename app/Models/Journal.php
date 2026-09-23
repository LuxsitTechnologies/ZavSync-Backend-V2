<?php

namespace App\Models;

use Database\Factories\JournalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journal extends Model
{
    /** @use HasFactory<JournalFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'sequence', 'number', 'posting_date', 'reference', 'reference_type', 'source_id', 'source', 'idempotency_key', 'idempotency_hash', 'description', 'status', 'created_by', 'posted_by', 'posted_at', 'reverses_journal_id', 'reversed_by_journal_id'];

    protected function casts(): array
    {
        return ['posting_date' => 'date:Y-m-d', 'posted_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
