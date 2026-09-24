<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CrmTag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmTag>
 */
class CrmTagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return ['company_id' => Company::factory(), 'name' => $name, 'normalized_name' => mb_strtolower($name), 'color' => '#2563eb', 'created_by' => User::factory()];
    }
}
