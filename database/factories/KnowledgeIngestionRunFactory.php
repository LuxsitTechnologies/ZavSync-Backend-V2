<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\KnowledgeIngestionRun;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeIngestionRun>
 */
class KnowledgeIngestionRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'knowledge_source_id' => KnowledgeSource::factory(), 'idempotency_key' => fake()->uuid(), 'source_version' => 1, 'status' => 'PENDING', 'attempt' => 0, 'chunk_count' => 0, 'requested_by' => User::factory()];
    }
}
