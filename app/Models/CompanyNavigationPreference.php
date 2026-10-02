<?php

namespace App\Models;

use Database\Factories\CompanyNavigationPreferenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyNavigationPreference extends Model
{
    /** @use HasFactory<CompanyNavigationPreferenceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'item_key', 'is_visible', 'updated_by'];

    protected function casts(): array
    {
        return ['is_visible' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
