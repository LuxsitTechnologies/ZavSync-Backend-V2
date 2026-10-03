<?php

namespace App\Models;

use Database\Factories\AttendanceRevisionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRevision extends Model
{
    /** @use HasFactory<AttendanceRevisionFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['company_id', 'attendance_session_id', 'attendance_correction_request_id', 'revision_number', 'before_snapshot', 'effective_snapshot', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['before_snapshot' => 'array', 'effective_snapshot' => 'array', 'created_at' => 'immutable_datetime', 'revision_number' => 'integer'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }
}
