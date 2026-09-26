<?php

namespace App\Models;

use Database\Factories\AiEvaluationCaseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiEvaluationCase extends Model
{
    /** @use HasFactory<AiEvaluationCaseFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'created_by', 'name', 'prompt', 'expected_citations', 'expected_tools', 'forbidden_actions', 'is_active'];

    protected function casts(): array
    {
        return ['prompt' => 'encrypted', 'expected_citations' => 'array', 'expected_tools' => 'array', 'forbidden_actions' => 'array', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiEvaluationRun::class);
    }
}
