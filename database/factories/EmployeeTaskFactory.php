<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeTask>
 */
class EmployeeTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'assigned_employee_id' => fn (array $attributes): string => Employee::factory()->for(Company::query()->findOrFail($attributes['company_id']))->create()->id,
            'created_by' => User::factory(), 'title' => fake()->sentence(4), 'description' => fake()->sentence(),
            'priority' => 'NORMAL', 'due_date' => now()->addWeek()->toDateString(), 'status' => 'ASSIGNED', 'version' => 1,
            'request_key_hash' => hash('sha256', fake()->uuid()), 'payload_hash' => hash('sha256', fake()->uuid()),
        ];
    }
}
