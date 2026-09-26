<?php

namespace Database\Factories;

use App\Models\AiMessage;
use App\Models\AiMessageCitation;
use App\Models\Company;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiMessageCitation>
 */
class AiMessageCitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'ai_message_id' => AiMessage::factory(), 'knowledge_source_id' => KnowledgeSource::factory(), 'knowledge_chunk_id' => KnowledgeChunk::factory(), 'ordinal' => 1, 'excerpt' => fake()->sentence(), 'locator' => ['section' => 'Test']];
    }
}
