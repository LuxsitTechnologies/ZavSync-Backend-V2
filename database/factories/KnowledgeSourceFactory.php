<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeSource>
 */
class KnowledgeSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = fake()->paragraphs(3, true);

        return ['company_id' => Company::factory(), 'source_type' => 'NOTE', 'title' => fake()->sentence(4), 'content' => $content, 'access_permission' => 'ai.knowledge.view', 'status' => 'PENDING', 'checksum_sha256' => hash('sha256', $content), 'version' => 1, 'chunk_count' => 0, 'metadata' => [], 'created_by' => User::factory()];
    }
}
