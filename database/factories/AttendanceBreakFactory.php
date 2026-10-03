<?php

namespace Database\Factories;

use App\Models\AttendanceBreak;
use App\Models\AttendanceSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceBreak>
 */
class AttendanceBreakFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'attendance_session_id' => AttendanceSession::factory(),
            'company_id' => fn (array $attributes): string => AttendanceSession::query()->findOrFail($attributes['attendance_session_id'])->company_id,
            'active_session_id' => null, 'started_at' => '2026-09-01 08:00:00',
            'ended_at' => '2026-09-01 08:30:00', 'duration_seconds' => 1800,
        ];
    }
}
