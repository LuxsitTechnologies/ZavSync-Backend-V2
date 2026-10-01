<?php

namespace App\Models;

use Database\Factories\FbrReferenceValueFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FbrReferenceValue extends Model
{
    /** @use HasFactory<FbrReferenceValueFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['category', 'code', 'label', 'parent_code', 'metadata', 'source', 'source_version', 'is_active', 'valid_from', 'valid_until'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'is_active' => 'boolean', 'valid_from' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d'];
    }
}
