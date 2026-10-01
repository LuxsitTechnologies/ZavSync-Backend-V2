<?php

namespace App\Models;

use Database\Factories\LegacyEntityMapFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyEntityMap extends Model
{
    /** @use HasFactory<LegacyEntityMapFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['import_run_id', 'company_id', 'source_system', 'source_entity_type', 'source_id', 'target_entity_type', 'target_id', 'safe_metadata'];

    protected function casts(): array
    {
        return ['safe_metadata' => 'array'];
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
