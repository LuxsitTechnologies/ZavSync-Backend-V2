<?php

namespace App\Models;

use Database\Factories\AiEvaluationRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiEvaluationRun extends Model
{
    /** @use HasFactory<AiEvaluationRunFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'ai_evaluation_case_id', 'run_by', 'status', 'answer', 'score_bps', 'checks', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['answer' => 'encrypted', 'score_bps' => 'integer', 'checks' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function evaluationCase(): BelongsTo
    {
        return $this->belongsTo(AiEvaluationCase::class, 'ai_evaluation_case_id');
    }
}
