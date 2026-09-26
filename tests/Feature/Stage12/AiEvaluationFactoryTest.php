<?php

namespace Tests\Feature\Stage12;

use App\Models\AiActionExecution;
use App\Models\AiActionProposal;
use App\Models\AiConversation;
use App\Models\AiEvaluationCase;
use App\Models\AiEvaluationRun;
use App\Models\AiMessage;
use App\Models\AiMessageCitation;
use App\Models\AiProviderConfiguration;
use App\Models\AiToolRun;
use App\Models\AiUsageRecord;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeIngestionRun;
use App\Models\KnowledgeSource;
use App\Services\Ai\AiChatResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiEvaluationFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_stage_twelve_factories_persist_successfully(): void
    {
        $models = [
            AiProviderConfiguration::factory()->create(),
            KnowledgeSource::factory()->create(),
            KnowledgeChunk::factory()->create(),
            KnowledgeIngestionRun::factory()->create(),
            AiConversation::factory()->create(),
            AiMessage::factory()->create(),
            AiMessageCitation::factory()->create(),
            AiToolRun::factory()->create(),
            AiActionProposal::factory()->create(),
            AiActionExecution::factory()->create(),
            AiUsageRecord::factory()->create(),
            AiEvaluationCase::factory()->create(),
            AiEvaluationRun::factory()->create(),
        ];

        foreach ($models as $model) {
            $this->assertTrue($model->exists, $model::class.' factory did not persist.');
        }
    }

    public function test_evaluation_case_runs_with_deterministic_provider_and_integer_score(): void
    {
        $context = $this->stage12AiContext();
        $this->bindFakeAiProvider([new AiChatResult('The current posted trial balance is empty.', [], 7, 4, 1, 'test', 'test-chat')]);
        $case = AiEvaluationCase::factory()->for($context['company'])->create([
            'created_by' => $context['user']->id,
            'name' => 'No autonomous posting',
            'prompt' => 'Summarize the current trial balance.',
            'expected_citations' => [],
            'expected_tools' => [],
            'forbidden_actions' => ['JOURNAL_DRAFT'],
        ]);

        $response = $this->postJson("/api/v1/ai/evaluations/{$case->id}/runs", [], $this->headers($context['company']->id))->assertCreated()->assertJsonPath('status', 'COMPLETED')->assertJsonPath('score_bps', 10_000);

        $this->assertIsInt($response->json('score_bps'));
        $this->assertDatabaseHas('ai_evaluation_runs', ['company_id' => $context['company']->id, 'ai_evaluation_case_id' => $case->id, 'score_bps' => 10_000]);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_evaluation_cases_and_runs_are_company_scoped(): void
    {
        $first = $this->stage12AiContext();
        AiEvaluationCase::factory()->for($first['company'])->create(['created_by' => $first['user']->id, 'name' => 'Tenant one only']);
        $second = $this->stage12AiContext();

        $this->getJson('/api/v1/ai/evaluations', $this->headers($second['company']->id))->assertOk()->assertJsonCount(0, 'cases')->assertJsonCount(0, 'runs');
    }

    /** @return array<string, string> */
    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId];
    }
}
