<?php

namespace App\Models;

use App\Enums\FbrSubmissionStatus;
use Database\Factories\FbrSubmissionAttemptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FbrSubmissionAttempt extends Model
{
    /** @use HasFactory<FbrSubmissionAttemptFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'invoice_id', 'idempotency_key', 'payload_hash', 'status', 'request_metadata', 'response_metadata', 'reference_number', 'error_message', 'submitted_by', 'completed_at'];

    protected function casts(): array
    {
        return ['status' => FbrSubmissionStatus::class, 'request_metadata' => 'array', 'response_metadata' => 'array', 'completed_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
