<?php

namespace Database\Factories;

use App\Models\AttendanceSession;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceSession>
 */
class AttendanceSessionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => fn (array $attributes): string => Employee::factory()->for(Company::query()->findOrFail($attributes['company_id']))->create()->id,
            'active_employee_id' => null,
            'work_date' => '2026-09-01', 'timezone' => 'Asia/Karachi', 'state' => 'CLOCKED_OUT',
            'clock_in_at' => '2026-09-01 04:00:00', 'clock_out_at' => '2026-09-01 12:00:00',
            'completed_break_seconds' => 0, 'worked_seconds' => 28800,
            'provenance' => 'SELF_CLOCK', 'created_by' => User::factory(),
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => 'CLOCKED_IN', 'clock_out_at' => null, 'worked_seconds' => null,
            'active_employee_id' => $attributes['employee_id'],
        ]);
    }
}
