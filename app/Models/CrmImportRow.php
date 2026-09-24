<?php

namespace App\Models;

use Database\Factories\CrmImportRowFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CrmImportRow extends Model
{
    /** @use HasFactory<CrmImportRowFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'crm_import_id', 'row_number', 'source_data', 'mapped_data', 'state', 'errors', 'warnings', 'created_record_type', 'created_record_id'];

    protected function casts(): array
    {
        return ['row_number' => 'integer', 'source_data' => 'array', 'mapped_data' => 'array', 'errors' => 'array', 'warnings' => 'array'];
    }

    public function crmImport(): BelongsTo
    {
        return $this->belongsTo(CrmImport::class);
    }

    public function createdRecord(): MorphTo
    {
        return $this->morphTo();
    }
}
