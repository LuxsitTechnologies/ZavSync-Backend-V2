<?php

namespace Database\Factories;

use App\Models\AiEvaluationCase;
use App\Models\AiEvaluationRun;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiEvaluationRun>
 */
class AiEvaluationRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'ai_evaluation_case_id' => AiEvaluationCase::factory(), 'run_by' => User::factory(), 'status' => 'COMPLETED', 'answer' => fake()->sentence(), 'score_bps' => 10_000, 'checks' => ['grounded' => true], 'started_at' => now(), 'completed_at' => now()];
    }
}
