<?php

namespace App\Models;

use Database\Factories\AiToolRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiToolRun extends Model
{
    /** @use HasFactory<AiToolRunFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'ai_message_id', 'user_id', 'tool_name', 'required_permission', 'status', 'input', 'output', 'error_code', 'error_message', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['input' => 'encrypted:array', 'output' => 'encrypted:array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
