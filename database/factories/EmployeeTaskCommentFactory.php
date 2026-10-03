<?php

namespace Database\Factories;

use App\Models\EmployeeTask;
use App\Models\EmployeeTaskComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeTaskComment>
 */
class EmployeeTaskCommentFactory extends Factory
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
            'author_id' => User::factory(), 'body' => fake()->sentence(), 'request_key_hash' => hash('sha256', fake()->uuid()),
            'payload_hash' => hash('sha256', fake()->uuid()), 'created_at' => now(),
        ];
    }
}
