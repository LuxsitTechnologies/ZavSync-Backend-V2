<?php

namespace App\Models;

use Database\Factories\CrmImportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmImport extends Model
{
    /** @use HasFactory<CrmImportFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'entity_type', 'original_filename', 'status', 'mapping', 'summary', 'file_hash', 'idempotency_key', 'confirmed_at', 'created_by'];

    protected function casts(): array
    {
        return ['mapping' => 'array', 'summary' => 'array', 'confirmed_at' => 'datetime'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(CrmImportRow::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
