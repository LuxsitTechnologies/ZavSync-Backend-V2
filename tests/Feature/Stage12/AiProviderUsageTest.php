<?php

namespace Tests\Feature\Stage12;

use App\Contracts\OutreachAiAssistant;
use App\Exceptions\PlatformException;
use App\Models\AiConversation;
use App\Models\AiUsageRecord;
use App\Models\CompanyEntitlement;
use App\Services\Ai\AiChatRequest;
use App\Services\Ai\AiChatResult;
use App\Services\Ai\OpenAiProvider;
use App\Services\Platform\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_configuration_encrypts_secret_and_never_serializes_it(): void
    {
        $context = $this->stage12AiContext();
        $configuration = $context['provider'];

        $this->assertSame('test-key', $configuration->api_key);
        $this->assertStringNotContainsString('test-key', (string) $configuration->getRawOriginal('api_key'));
        $this->assertArrayNotHasKey('api_key', $configuration->toArray());
        $this->getJson('/api/v1/ai/provider-configuration', $this->headers($context['company']->id))->assertOk()->assertJsonMissing(['api_key' => 'test-key'])->assertJsonPath('has_api_key', true);
    }

    public function test_provider_update_requires_company_external_provider_setting(): void
    {
        $context = $this->stage12AiContext();
        $context['company']->settings()->update(['ai_allow_external_provider' => false]);

        $this->putJson('/api/v1/ai/provider-configuration', $this->providerPayload(), $this->headers($context['company']->id))->assertConflict()->assertJsonPath('error_code', 'AI_EXTERNAL_PROVIDER_DISABLED');
    }

    public function test_provider_update_preserves_existing_secret_when_api_key_is_omitted(): void
    {
        $context = $this->stage12AiContext();
        $payload = $this->providerPayload();
        unset($payload['api_key']);

        $this->putJson('/api/v1/ai/provider-configuration', $payload, $this->headers($context['company']->id))->assertOk()->assertJsonPath('configuration.chat_model', 'gpt-test')->assertJsonMissing(['api_key' => 'test-key']);

        $this->assertSame('test-key', $context['provider']->fresh()->api_key);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id, 'action' => 'ai_provider_configuration_updated']);
    }

    public function test_chat_returns_structured_error_when_provider_configuration_is_missing(): void
    {
        $context = $this->stage12AiContext();
        $context['provider']->delete();
        $conversation = AiConversation::factory()->for($context['company'])->for($context['user'])->create();

        $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['content' => 'Summarize cash.'], $this->headers($context['company']->id, 'missing-provider'))->assertConflict()->assertJsonPath('error_code', 'AI_PROVIDER_NOT_CONFIGURED');
    }

    public function test_usage_api_reports_integer_token_and_minor_cost_totals(): void
    {
        $context = $this->stage12AiContext();
        AiUsageRecord::factory()->for($context['company'])->count(2)->create(['user_id' => $context['user']->id, 'input_tokens' => 100, 'output_tokens' => 25, 'cost_minor' => 9, 'occurred_at' => now()]);

        $this->getJson('/api/v1/ai/usage', $this->headers($context['company']->id))->assertOk()->assertJsonPath('summary.requests', 2)->assertJsonPath('summary.input_tokens', 200)->assertJsonPath('summary.output_tokens', 50)->assertJsonPath('summary.cost_minor', 18)->assertJsonPath('limits.daily_ai_requests', 500);
        $this->assertIsInt(AiUsageRecord::query()->firstOrFail()->cost_minor);
    }

    public function test_daily_ai_request_limit_is_enforced_server_side(): void
    {
        $context = $this->stage12AiContext();
        CompanyEntitlement::query()->where('company_id', $context['company']->id)->where('module_key', 'ai')->update(['limits' => ['daily_ai_requests' => 0, 'monthly_ai_tokens' => 1000, 'monthly_ai_cost_minor' => 1000, 'knowledge_sources' => 10, 'knowledge_chunks' => 100]]);
        app(EntitlementService::class)->forget($context['company']->id);
        $conversation = AiConversation::factory()->for($context['company'])->for($context['user'])->create();

        $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['content' => 'Summarize cash.'], $this->headers($context['company']->id, 'limit'))->assertConflict()->assertJsonPath('error_code', 'SUBSCRIPTION_LIMIT_REACHED');
    }

    public function test_openai_adapter_sends_untrusted_knowledge_and_parses_structured_citations_and_proposals(): void
    {
        $context = $this->stage12AiContext();
        $configuration = $context['provider'];
        $configuration->update(['provider' => 'openai', 'settings' => ['input_cost_per_million_minor' => 1_000_000, 'output_cost_per_million_minor' => 2_000_000]]);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode(['answer' => 'The close policy requires reconciliation. [1]', 'citation_ordinals' => [1], 'proposed_actions' => [['action_type' => 'CRM_ACTIVITY_DRAFT', 'payload_json' => '{"type":"TASK","subject":"Review close"}']]], JSON_THROW_ON_ERROR),
                'output' => [],
                'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
            ]),
        ]);

        $result = app(OpenAiProvider::class)->chat(new AiChatRequest('System boundary', [['role' => 'user', 'content' => 'What is the close policy?']], [['ordinal' => 1, 'content' => 'Reconcile before close.']], []), $configuration->fresh());

        $this->assertSame('The close policy requires reconciliation. [1]', $result->answer);
        $this->assertSame([1], $result->citationOrdinals);
        $this->assertSame('CRM_ACTIVITY_DRAFT', $result->proposedActions[0]['action_type']);
        $this->assertSame(7, $result->costMinor);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return str_contains((string) ($payload['input'][1]['content'] ?? ''), 'UNTRUSTED CONTENT')
                && ($payload['store'] ?? null) === false
                && ($payload['text']['format']['type'] ?? null) === 'json_schema';
        });
    }

    public function test_openai_embedding_adapter_uses_integer_vectors_and_integer_minor_cost(): void
    {
        $context = $this->stage12AiContext();
        $configuration = $context['provider'];
        $configuration->update(['provider' => 'openai', 'settings' => ['embedding_cost_per_million_minor' => 1_000_000]]);
        Http::fake(['api.openai.com/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => [0.25, -0.5]]], 'usage' => ['prompt_tokens' => 3]])]);

        $result = app(OpenAiProvider::class)->embed(['Policy text'], $configuration->fresh());

        $this->assertSame([[250_000, -500_000]], $result->vectors);
        $this->assertSame(3, $result->costMinor);
        $this->assertContainsOnly('int', $result->vectors[0]);
    }

    public function test_openai_provider_failure_does_not_expose_the_remote_error_body(): void
    {
        $context = $this->stage12AiContext();
        $configuration = $context['provider'];
        $configuration->update(['provider' => 'openai']);
        Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'remote-secret-detail']], 500)]);

        try {
            app(OpenAiProvider::class)->chat(new AiChatRequest('System boundary', [['role' => 'user', 'content' => 'Question']], [], []), $configuration->fresh());
            $this->fail('Expected the provider request to fail.');
        } catch (PlatformException $exception) {
            $this->assertSame('AI_PROVIDER_REQUEST_FAILED', $exception->errorCode);
            $this->assertStringNotContainsString('remote-secret-detail', $exception->getMessage());
        }
    }

    public function test_outreach_ai_authoring_reuses_provider_boundary_without_sending_or_exposing_tenant_ids(): void
    {
        $context = $this->stage12AiContext();
        $fake = $this->bindFakeAiProvider([new AiChatResult('', [], 8, 5, 2, 'test', 'test-chat', [], [], ['subject' => 'A draft', 'body_text' => 'Draft content only.', 'body_html' => null])]);

        $draft = app(OutreachAiAssistant::class)->draft('Prepare an introduction.', ['company_id' => $context['company']->id, 'user_id' => $context['user']->id, 'contact_name' => 'Ayesha']);

        $this->assertSame('A draft', $draft['subject']);
        $this->assertSame('Draft content only.', $draft['body_text']);
        $this->assertArrayNotHasKey('company_id', $fake->requests[0]->knowledge[0]['context']);
        $this->assertArrayNotHasKey('user_id', $fake->requests[0]->knowledge[0]['context']);
        $this->assertDatabaseHas('ai_usage_records', ['company_id' => $context['company']->id, 'user_id' => $context['user']->id, 'operation' => 'OUTREACH_DRAFT']);
        $this->assertDatabaseCount('outreach_messages', 0);
    }

    /** @return array<string, mixed> */
    private function providerPayload(): array
    {
        return ['provider' => 'openai', 'chat_model' => 'gpt-test', 'embedding_model' => 'embed-test', 'api_key' => 'replacement-secret', 'settings' => ['input_cost_per_million_minor' => 100, 'output_cost_per_million_minor' => 200, 'embedding_cost_per_million_minor' => 10], 'is_enabled' => true];
    }

    /** @return array<string, string> */
    private function headers(string $companyId, ?string $idempotencyKey = null): array
    {
        return array_filter(['X-Company-Id' => $companyId, 'Idempotency-Key' => $idempotencyKey]);
    }
}
