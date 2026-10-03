<?php

namespace App\Models;

use Database\Factories\AttendanceCorrectionRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrectionRequest extends Model
{
    /** @use HasFactory<AttendanceCorrectionRequestFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_id', 'attendance_session_id', 'kind', 'status', 'request_key_hash', 'original_snapshot', 'proposed_snapshot', 'reason', 'decision_reason', 'submitted_by', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return ['original_snapshot' => 'array', 'proposed_snapshot' => 'array', 'decided_at' => 'immutable_datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }
}
