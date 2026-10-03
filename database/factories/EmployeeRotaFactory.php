<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EmployeeRota;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeRota>
 */
class EmployeeRotaFactory extends Factory
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
            'name' => 'Rota '.fake()->unique()->numerify('####'),
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeeks(2)->toDateString(),
            'timezone' => 'Asia/Karachi',
            'created_by' => User::factory(),
        ];
    }
}
