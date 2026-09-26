<?php

namespace Database\Factories;

use App\Models\AiActionExecution;
use App\Models\AiActionProposal;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiActionExecution>
 */
class AiActionExecutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'ai_action_proposal_id' => AiActionProposal::factory(), 'executed_by' => User::factory(), 'status' => 'COMPLETED', 'idempotency_key' => fake()->uuid(), 'result_type' => 'crm_activity', 'result_id' => fake()->uuid(), 'result_summary' => 'Draft created.', 'started_at' => now(), 'completed_at' => now()];
    }
}
