<?php

namespace App\Models;

use Database\Factories\LegacyImportRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegacyImportRun extends Model
{
    /** @use HasFactory<LegacyImportRunFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'source_system', 'source_fingerprint', 'source_company_id', 'status', 'mode', 'source_filename', 'source_manifest', 'progress', 'reconciliation', 'failure_message', 'created_by', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['source_manifest' => 'array', 'progress' => 'array', 'reconciliation' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function entityMaps(): HasMany
    {
        return $this->hasMany(LegacyEntityMap::class, 'import_run_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(MigrationException::class, 'import_run_id');
    }
}
