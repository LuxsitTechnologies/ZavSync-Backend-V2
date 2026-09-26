<?php

namespace Database\Factories;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiMessage>
 */
class AiMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'ai_conversation_id' => AiConversation::factory(), 'user_id' => User::factory(), 'role' => 'USER', 'content' => fake()->sentence(), 'status' => 'COMPLETED', 'input_tokens' => 0, 'output_tokens' => 0, 'cost_minor' => 0, 'metadata' => []];
    }
}
