<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeChunk>
 */
class KnowledgeChunkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = fake()->paragraph();

        return ['company_id' => Company::factory(), 'knowledge_source_id' => KnowledgeSource::factory(), 'source_version' => 1, 'chunk_index' => 0, 'content' => $content, 'content_hash' => hash('sha256', $content), 'locator' => ['section' => 'Test'], 'embedding' => [1, 0, 1]];
    }
}
