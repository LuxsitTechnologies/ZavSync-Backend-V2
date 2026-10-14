<?php

namespace App\Models;

use App\Enums\FbrSubmissionStatus;
use Database\Factories\PakistanFbrCreditNoteAttemptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PakistanFbrCreditNoteAttempt extends Model
{
    /** @use HasFactory<PakistanFbrCreditNoteAttemptFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'credit_note_id', 'idempotency_key', 'payload_hash', 'claim_generation', 'status', 'reference_number', 'response_metadata', 'error_message', 'submitted_by', 'completed_at'];

    protected $hidden = ['idempotency_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['claim_generation' => 'integer', 'status' => FbrSubmissionStatus::class, 'response_metadata' => 'array', 'completed_at' => 'datetime'];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(PakistanFbrCreditNote::class, 'credit_note_id');
    }
}
