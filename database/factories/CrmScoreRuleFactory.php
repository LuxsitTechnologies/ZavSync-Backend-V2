<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CrmScoreRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmScoreRule>
 */
class CrmScoreRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'name' => fake()->unique()->sentence(3), 'target_type' => 'LEAD',
            'field' => 'email', 'operator' => 'NOT_EMPTY', 'comparison_value' => null, 'points' => 10,
            'position' => fake()->unique()->numberBetween(1, 1000), 'is_active' => true,
            'created_by' => User::factory(), 'updated_by' => null,
        ];
    }
}
