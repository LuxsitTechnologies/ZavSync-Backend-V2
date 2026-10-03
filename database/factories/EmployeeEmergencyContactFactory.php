<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeEmergencyContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeEmergencyContact>
 */
class EmployeeEmergencyContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'employee_id' => fn (array $attributes): string => Employee::factory()->create(['company_id' => $attributes['company_id']])->id,
            'name' => fake()->name(), 'relationship' => 'Family', 'phone' => fake()->phoneNumber(),
            'created_by' => User::factory()];
    }
}
