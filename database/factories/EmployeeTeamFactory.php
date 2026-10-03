<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EmployeeTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeTeam>
 */
class EmployeeTeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'name' => fake()->unique()->words(2, true),
            'version' => 1, 'created_by' => User::factory()];
    }
}
