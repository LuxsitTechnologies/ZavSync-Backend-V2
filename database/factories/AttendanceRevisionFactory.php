<?php

namespace Database\Factories;

use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRevision>
 */
class AttendanceRevisionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'attendance_correction_request_id' => AttendanceCorrectionRequest::factory(),
            'company_id' => fn (array $attributes): string => AttendanceCorrectionRequest::query()->findOrFail($attributes['attendance_correction_request_id'])->company_id,
            'attendance_session_id' => fn (array $attributes): string => AttendanceCorrectionRequest::query()->findOrFail($attributes['attendance_correction_request_id'])->attendance_session_id,
            'revision_number' => 1,
            'before_snapshot' => fn (array $attributes): array => AttendanceCorrectionRequest::query()->findOrFail($attributes['attendance_correction_request_id'])->original_snapshot,
            'effective_snapshot' => fn (array $attributes): array => AttendanceCorrectionRequest::query()->findOrFail($attributes['attendance_correction_request_id'])->proposed_snapshot,
            'created_by' => User::factory(), 'created_at' => now('UTC'),
        ];
    }
}
