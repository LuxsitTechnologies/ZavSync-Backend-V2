<?php

namespace App\Models;

use Database\Factories\AttendanceBreakFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceBreak extends Model
{
    /** @use HasFactory<AttendanceBreakFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'attendance_session_id', 'active_session_id', 'started_at', 'ended_at', 'duration_seconds'];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'duration_seconds' => 'integer'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }
}
