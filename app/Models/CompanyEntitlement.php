<?php

namespace App\Models;

use Database\Factories\CompanyEntitlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyEntitlement extends Model
{
    /** @use HasFactory<CompanyEntitlementFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'module_key', 'is_enabled', 'limits', 'expires_at', 'updated_by'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'limits' => 'array', 'expires_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
