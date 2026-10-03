<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EmployeeShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeShift>
 */
class EmployeeShiftFactory extends Factory
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
            'name' => 'Day '.fake()->unique()->numerify('####'),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }
}
