<?php

namespace Tests\Feature\Stage12;

use App\Models\AiActionProposal;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiToolRun;
use App\Models\CompanyEntitlement;
use App\Models\Customer;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Services\Ai\AiChatResult;
use App\Services\Platform\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CopilotConversationToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_message_returns_grounded_answer_with_verified_citation(): void
    {
        $context = $this->stage12AiContext();
        $this->bindFakeAiProvider([new AiChatResult('Reconcile the bank before close. [1]', [], 20, 10, 4, 'test', 'test-chat', [1])]);
        $source = KnowledgeSource::factory()->for($context['company'])->create(['created_by' => $context['user']->id, 'title' => 'Close policy', 'status' => 'READY', 'access_permission' => 'accounting.view', 'indexed_at' => now()]);
        KnowledgeChunk::factory()->for($context['company'])->for($source, 'source')->create(['content' => 'Reconcile the bank before closing the accounting period.', 'embedding' => [100, 50, 25]]);
        $conversation = AiConversation::factory()->for($context['company'])->for($context['user'])->create(['title' => 'New conversation']);

        $response = $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['content' => 'What is the close policy?'], $this->headers($context['company']->id, 'chat-1'))->assertCreated()->assertJsonPath('role', 'ASSISTANT')->assertJsonPath('status', 'COMPLETED')->assertJsonPath('citations.0.knowledge_source_id', $source->id);

        $this->assertSame('Reconcile the bank before close. [1]', $response->json('content'));
        $this->assertDatabaseHas('ai_usage_records', ['company_id' => $context['company']->id, 'operation' => 'CHAT', 'cost_minor' => 4]);
        $this->assertSame('What is the close policy?', $conversation->fresh()->title);
    }

    public function test_allowlisted_read_only_tool_runs_then_provider_synthesizes_final_answer(): void
    {
        $context = $this->stage12AiContext();
        $fake = $this->bindFakeAiProvider([
            new AiChatResult('', [['name' => 'accounting.trial_balance', 'arguments' => []]], 10, 2, 1, 'test', 'test-chat'),
            new AiChatResult('The posted trial balance is currently empty.', [], 8, 7, 1, 'test', 'test-chat'),
        ]);
        $conversation = AiConversation::factory()->for($context['company'])->for($context['user'])->create();

        $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['content' => 'Show the trial balance.'], $this->headers($context['company']->id, 'tool-1'))->assertCreated()->assertJsonPath('content', 'The posted trial balance is currently empty.');

        $this->assertSame(2, count($fake->requests));
        $this->assertNotEmpty($fake->requests[1]->toolResults);
        $this->assertDatabaseHas('ai_tool_runs', ['company_id' => $context['company']->id, 'tool_name' => 'accounting.trial_balance', 'status' => 'COMPLETED']);
    }

    public function test_non_allowlisted_tool_call_is_rejected_and_never_executes(): void
    {
        $context = $this->stage12AiContext();
        $this->bindFakeAiProvider([new AiChatResult('', [['name' => 'database.raw_sql', 'arguments' => ['query' => 'DELETE FROM journals']]], 10, 1, 1, 'test', 'test-chat')]);
        $conversation = AiConversation::factory()->for($context['company'])->for($context['user'])->create();

        $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['content' => 'Run a database command.'], $this->headers($context['company']->id, 'tool-denied'))->assertUnprocessable()->assertJsonPath('error_code', 'AI_TOOL_NOT_ALLOWED');

        $this->assertSame('FAILED', AiMessage::query()->where('role', 'ASSISTANT')->firstOrFail()->status);
        $this->assertSame(0, AiToolRun::query()->count());
    }

    public function test_provider_proposed_action_is_pending_and_does_not_mutate_business_data(): void
    {
        $context = $this->stage12AiContext();
        $this->bindFakeAiProvider([new AiChatResult('I prepared a task proposal for review.', [], 10, 6, 2, 'test', 'test-chat', [], [['action_type' => 'CRM_ACTIVITY_DRAFT', 'payload' => ['type' => 'TASK', 'subject' => 'Call the customer', 'status' => 'PENDING', 'priority' => 'HIGH']]])]);
        $conversation = AiConversation::factory()->for($context['company'])->for($context['user'])->create();

        $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['content' => 'Create a follow-up task.'], $this->headers($context['company']->id, 'proposal-chat'))->assertCreated()->assertJsonPath('content', 'I prepared a task proposal for review.');

        $this->assertDatabaseHas('ai_action_proposals', ['company_id' => $context['company']->id, 'action_type' => 'CRM_ACTIVITY_DRAFT', 'status' => 'PENDING']);
        $this->assertDatabaseCount('crm_activities', 0);
        $this->assertSame('Create a CRM activity or task; no financial effect.', AiActionProposal::query()->firstOrFail()->impact_preview['summary']);
    }

    public function test_message_idempotency_returns_existing_answer_without_second_provider_call(): void
    {
        $context = $this->stage12AiContext();
        $fake = $this->bindFakeAiProvider([new AiChatResult('One answer.', [], 4, 3, 1, 'test', 'test-chat')]);
        $conversation = AiConversation::factory()->for($context['company'])->for($context['user'])->create();
        $headers = $this->headers($context['company']->id, 'same-message');

        $first = $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['content' => 'One question.'], $headers)->assertCreated();
        $second = $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['content' => 'One question.'], $headers)->assertCreated();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, count($fake->requests));
        $this->assertSame(2, AiMessage::query()->where('ai_conversation_id', $conversation->id)->count());
    }

    public function test_conversation_show_and_archive_are_owner_scoped(): void
    {
        $context = $this->stage12AiContext();
        $conversation = AiConversation::factory()->for($context['company'])->for($context['user'])->create();

        $this->getJson("/api/v1/ai/conversations/{$conversation->id}", $this->headers($context['company']->id))->assertOk()->assertJsonPath('id', $conversation->id);
        $this->postJson("/api/v1/ai/conversations/{$conversation->id}/archive", [], $this->headers($context['company']->id))->assertOk();
        $this->getJson('/api/v1/ai/conversations', $this->headers($context['company']->id))->assertOk()->assertJsonCount(0, 'data');
        $this->assertNotNull($conversation->fresh()->archived_at);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id, 'action' => 'ai_conversation_archived']);
    }

    public function test_tools_endpoint_exposes_only_tools_for_held_domain_permissions(): void
    {
        $context = $this->stage12AiContext(['ai.copilot.use', 'ai.tools.use', 'accounting.view']);

        $response = $this->getJson('/api/v1/ai/tools', $this->headers($context['company']->id))->assertOk();

        $this->assertSame(['accounting.trial_balance', 'receivables.aging'], collect($response->json('data'))->pluck('name')->all());
    }

    public function test_tool_arguments_reject_unknown_fields_before_execution(): void
    {
        $context = $this->stage12AiContext();
        $this->bindFakeAiProvider([new AiChatResult('', [['name' => 'accounting.trial_balance', 'arguments' => ['sql' => 'select * from users']]], 2, 1, 0, 'test', 'test-chat')]);
        $conversation = AiConversation::factory()->for($context['company'])->for($context['user'])->create();

        $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['content' => 'Show the trial balance.'], $this->headers($context['company']->id, 'tool-schema'))->assertUnprocessable()->assertJsonValidationErrors('arguments');

        $this->assertDatabaseCount('ai_tool_runs', 0);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_tool_cannot_use_another_company_customer_identifier(): void
    {
        $first = $this->stage12AiContext();
        $foreignCustomer = Customer::factory()->for($first['company'])->create(['created_by' => $first['user']->id]);
        $second = $this->stage12AiContext();
        $this->bindFakeAiProvider([new AiChatResult('', [['name' => 'receivables.aging', 'arguments' => ['customer_id' => $foreignCustomer->id]]], 2, 1, 0, 'test', 'test-chat')]);
        $conversation = AiConversation::factory()->for($second['company'])->for($second['user'])->create();

        $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['content' => 'Show this customer aging.'], $this->headers($second['company']->id, 'foreign-customer'))->assertUnprocessable()->assertJsonValidationErrors('customer_id');

        $this->assertDatabaseCount('ai_tool_runs', 0);
    }

    public function test_domain_permission_is_enforced_when_provider_requests_a_tool(): void
    {
        $context = $this->stage12AiContext(['ai.copilot.use', 'ai.tools.use']);
        $this->bindFakeAiProvider([new AiChatResult('', [['name' => 'payroll.summary', 'arguments' => []]], 2, 1, 0, 'test', 'test-chat')]);
        $conversation = AiConversation::factory()->for($context['company'])->for($context['user'])->create();

        $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['content' => 'Show payroll.'], $this->headers($context['company']->id, 'payroll-denied'))->assertForbidden()->assertJsonPath('error_code', 'AI_TOOL_PERMISSION_DENIED');

        $this->assertDatabaseCount('ai_tool_runs', 0);
    }

    public function test_insufficient_evidence_returns_a_deterministic_refusal_without_fabricated_citations(): void
    {
        $context = $this->stage12AiContext();
        $this->bindFakeAiProvider([new AiChatResult('Invented internal policy answer. [1]', [], 5, 4, 1, 'test', 'test-chat', [1])]);
        $conversation = AiConversation::factory()->for($context['company'])->for($context['user'])->create();

        $response = $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['content' => 'What is our undocumented travel policy?'], $this->headers($context['company']->id, 'insufficient-evidence'))->assertCreated();

        $this->assertSame('The available company knowledge and authorized business tools do not provide enough evidence to answer that request.', $response->json('content'));
        $this->assertSame([], $response->json('citations'));
    }

    public function test_tool_execution_rechecks_the_target_module_entitlement(): void
    {
        $context = $this->stage12AiContext();
        CompanyEntitlement::query()->where('company_id', $context['company']->id)->where('module_key', 'payroll')->update(['is_enabled' => false]);
        app(EntitlementService::class)->forget($context['company']->id);
        $this->bindFakeAiProvider([new AiChatResult('', [['name' => 'payroll.summary', 'arguments' => []]], 2, 1, 0, 'test', 'test-chat')]);
        $conversation = AiConversation::factory()->for($context['company'])->for($context['user'])->create();

        $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['content' => 'Show payroll summary.'], $this->headers($context['company']->id, 'payroll-entitlement'))->assertForbidden()->assertJsonPath('error_code', 'MODULE_NOT_ENTITLED');

        $this->assertDatabaseCount('ai_tool_runs', 0);
    }

    /** @return array<string, string> */
    private function headers(string $companyId, ?string $key = null): array
    {
        return array_filter(['X-Company-Id' => $companyId, 'Idempotency-Key' => $key]);
    }
}
