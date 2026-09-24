<?php

namespace App\Models;

use Database\Factories\CrmScoreRuleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmScoreRule extends Model
{
    /** @use HasFactory<CrmScoreRuleFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'name', 'target_type', 'field', 'operator', 'comparison_value', 'points', 'position', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['points' => 'integer', 'position' => 'integer', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
