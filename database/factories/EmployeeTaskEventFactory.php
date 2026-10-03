<?php

namespace Database\Factories;

use App\Models\EmployeeTask;
use App\Models\EmployeeTaskEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeTaskEvent>
 */
class EmployeeTaskEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_task_id' => EmployeeTask::factory(),
            'company_id' => fn (array $attributes): string => EmployeeTask::query()->findOrFail($attributes['employee_task_id'])->company_id,
            'actor_id' => User::factory(), 'action' => 'CREATED', 'from_status' => null, 'to_status' => 'ASSIGNED', 'created_at' => now(),
        ];
    }
}
