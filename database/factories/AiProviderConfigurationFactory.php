<?php

namespace Database\Factories;

use App\Models\AiProviderConfiguration;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProviderConfiguration>
 */
class AiProviderConfigurationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'provider' => 'test', 'chat_model' => 'test-chat', 'embedding_model' => 'test-embedding', 'api_key' => 'test-key', 'settings' => [], 'is_enabled' => true, 'updated_by' => User::factory()];
    }
}
