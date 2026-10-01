<?php

namespace App\Models;

use Database\Factories\MigrationExceptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationException extends Model
{
    /** @use HasFactory<MigrationExceptionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['import_run_id', 'company_id', 'source_entity_type', 'source_id', 'target_id', 'exception_code', 'severity', 'safe_metadata', 'resolution_state', 'resolution_note', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['safe_metadata' => 'array', 'resolved_at' => 'datetime'];
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(LegacyImportRun::class, 'import_run_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
