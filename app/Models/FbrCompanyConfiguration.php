<?php

namespace App\Models;

use Database\Factories\FbrCompanyConfigurationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FbrCompanyConfiguration extends Model
{
    /** @use HasFactory<FbrCompanyConfigurationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'seller_tax_identifier', 'seller_business_name', 'seller_province', 'seller_address', 'environment', 'credential', 'connection_state', 'last_verified_at', 'last_error', 'updated_by'];

    protected $hidden = ['credential'];

    protected function casts(): array
    {
        return ['credential' => 'encrypted', 'last_verified_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
