<?php

namespace Database\Factories;

use App\Models\AiUsageRecord;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageRecord>
 */
class AiUsageRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'user_id' => User::factory(), 'provider' => 'test', 'model' => 'test-chat', 'operation' => 'CHAT', 'input_tokens' => 100, 'output_tokens' => 50, 'cost_minor' => 25, 'metadata' => [], 'occurred_at' => now()];
    }
}
