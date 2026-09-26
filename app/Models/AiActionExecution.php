<?php

namespace App\Models;

use Database\Factories\AiActionExecutionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiActionExecution extends Model
{
    /** @use HasFactory<AiActionExecutionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'ai_action_proposal_id', 'executed_by', 'status', 'idempotency_key', 'result_type', 'result_id', 'result_summary', 'error_code', 'error_message', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(AiActionProposal::class, 'ai_action_proposal_id');
    }
}
