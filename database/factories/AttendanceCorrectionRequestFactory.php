<?php

namespace Database\Factories;

use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceCorrectionRequest>
 */
class AttendanceCorrectionRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'attendance_session_id' => AttendanceSession::factory(),
            'company_id' => fn (array $attributes): string => AttendanceSession::query()->findOrFail($attributes['attendance_session_id'])->company_id,
            'employee_id' => fn (array $attributes): string => AttendanceSession::query()->findOrFail($attributes['attendance_session_id'])->employee_id,
            'kind' => 'EMPLOYEE_REQUEST', 'status' => 'PENDING', 'request_key_hash' => null,
            'original_snapshot' => ['clock_in_at' => '2026-09-01T04:00:00+00:00', 'clock_out_at' => '2026-09-01T12:00:00+00:00', 'breaks' => [], 'break_seconds' => 0, 'worked_seconds' => 28800],
            'proposed_snapshot' => ['clock_in_at' => '2026-09-01T04:00:00+00:00', 'clock_out_at' => '2026-09-01T12:30:00+00:00', 'breaks' => [], 'break_seconds' => 0, 'worked_seconds' => 30600],
            'reason' => 'Forgot to clock out.', 'submitted_by' => User::factory(),
        ];
    }
}
