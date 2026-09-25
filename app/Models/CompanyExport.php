<?php

namespace App\Models;

use Database\Factories\CompanyExportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyExport extends Model
{
    /** @use HasFactory<CompanyExportFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'requested_by', 'status', 'sections', 'storage_disk', 'storage_key', 'completed_at', 'expires_at', 'failure_message'];

    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return ['sections' => 'array', 'completed_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
