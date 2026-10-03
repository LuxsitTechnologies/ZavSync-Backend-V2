<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyHoliday;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyHoliday>
 */
class CompanyHolidayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'name' => fake()->unique()->words(2, true),
            'date' => now()->addMonth()->toDateString(), 'is_active' => true, 'created_by' => User::factory()];
    }
}
