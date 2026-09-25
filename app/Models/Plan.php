<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = ['code', 'name', 'description', 'price_minor', 'currency', 'billing_interval', 'usage_limits', 'features', 'is_active'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'usage_limits' => 'array', 'features' => 'array', 'is_active' => 'boolean'];
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(PlatformModule::class, 'plan_modules', 'plan_id', 'module_key')->withPivot('is_enabled')->withTimestamps();
    }
}
