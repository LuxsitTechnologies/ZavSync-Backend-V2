<?php

namespace Database\Factories;

use App\Models\AiEvaluationCase;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiEvaluationCase>
 */
class AiEvaluationCaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'created_by' => User::factory(), 'name' => fake()->sentence(3), 'prompt' => fake()->sentence(), 'expected_citations' => [], 'expected_tools' => [], 'forbidden_actions' => ['direct_mutation'], 'is_active' => true];
    }
}
