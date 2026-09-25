<?php

namespace App\Models;

use Database\Factories\OutreachEnrollmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutreachEnrollment extends Model
{
    /** @use HasFactory<OutreachEnrollmentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'sequence_id', 'recipient_type', 'recipient_id', 'recipient_email', 'recipient_name', 'status', 'current_step_position', 'next_action_at', 'idempotency_key', 'idempotency_hash', 'enrolled_at', 'ended_at', 'created_by'];

    protected function casts(): array
    {
        return ['current_step_position' => 'integer', 'next_action_at' => 'datetime', 'enrolled_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(OutreachSequence::class, 'sequence_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OutreachMessage::class, 'enrollment_id');
    }
}
