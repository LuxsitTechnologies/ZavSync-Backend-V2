<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'uploaded_by', 'documentable_type', 'documentable_id', 'category', 'original_filename', 'storage_disk', 'storage_key', 'mime_type', 'size_bytes', 'checksum_sha256'];

    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'employee_released_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
