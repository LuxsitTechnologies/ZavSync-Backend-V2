<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'employee_code' => fake()->unique()->bothify('EMP-####'), 'full_name' => fake()->name(), 'email' => fake()->unique()->safeEmail(), 'phone' => fake()->phoneNumber(), 'department' => fake()->randomElement(['Finance', 'Operations', 'People']), 'designation' => fake()->jobTitle(), 'employment_type' => 'full_time', 'status' => 'active', 'joining_date' => '2026-01-01', 'leaving_date' => null, 'location' => fake()->city(), 'created_by' => User::factory(), 'updated_by' => null];
    }
}
