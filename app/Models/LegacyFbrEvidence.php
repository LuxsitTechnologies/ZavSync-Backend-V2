<?php

namespace App\Models;

use Database\Factories\LegacyFbrEvidenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LegacyFbrEvidence extends Model
{
    /** @use HasFactory<LegacyFbrEvidenceFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Historical FBR evidence is immutable.'));
        static::deleting(fn (): never => throw new LogicException('Historical FBR evidence is immutable.'));
    }

    protected $fillable = ['company_id', 'invoice_id', 'import_run_id', 'source_system', 'source_id', 'original_status', 'normalized_status', 'fbr_reference_number', 'retry_count', 'last_attempt_at', 'source_created_at', 'source_updated_at', 'original_timestamps', 'sanitized_response', 'requires_review', 'submission_blocked'];

    protected function casts(): array
    {
        return ['retry_count' => 'integer', 'last_attempt_at' => 'datetime', 'source_created_at' => 'datetime', 'source_updated_at' => 'datetime', 'original_timestamps' => 'array', 'sanitized_response' => 'array', 'requires_review' => 'boolean', 'submission_blocked' => 'boolean'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PakistanFbrInvoice::class, 'invoice_id');
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(LegacyImportRun::class, 'import_run_id');
    }
}
