<?php

namespace App\Models;

use Database\Factories\AttendanceSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceSession extends Model
{
    /** @use HasFactory<AttendanceSessionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_id', 'active_employee_id', 'work_date', 'timezone', 'state', 'clock_in_at', 'clock_out_at', 'completed_break_seconds', 'worked_seconds', 'provenance', 'created_by'];

    protected function casts(): array
    {
        return ['work_date' => 'date:Y-m-d', 'clock_in_at' => 'immutable_datetime', 'clock_out_at' => 'immutable_datetime', 'completed_break_seconds' => 'integer', 'worked_seconds' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(AttendanceBreak::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AttendanceRevision::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrectionRequest::class);
    }
}
