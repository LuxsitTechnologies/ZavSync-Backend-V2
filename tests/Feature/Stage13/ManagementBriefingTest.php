<?php

namespace Tests\Feature\Stage13;

use App\Models\OperationalPrioritySignal;
use App\Services\Ai\AiChatResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementBriefingTest extends TestCase
{
    use RefreshDatabase;

    public function test_briefing_returns_deterministic_structure_without_provider_request(): void
    {
        $context = $this->stage13IntelligenceContext();
        $context['provider']->update(['is_enabled' => false]);
        OperationalPrioritySignal::factory()->for($context['company'])->create(['category' => 'CRM', 'source_module' => 'crm', 'explanation_metadata' => ['required_permission' => 'crm.view'], 'effective_at' => now()]);

        $this->getJson('/api/v1/ai/intelligence/briefing?period=TODAY', $this->headers($context['company']->id))->assertOk()->assertJsonPath('status', 'DETERMINISTIC')->assertJsonPath('structured_data.generated_from', 'AUTHORITATIVE_SIGNALS')->assertJsonCount(1, 'structured_data.top_priorities')->assertJsonPath('narrative', null);

        $this->assertDatabaseHas('intelligence_briefings', ['company_id' => $context['company']->id, 'status' => 'DETERMINISTIC']);
    }

    public function test_briefing_ai_explanation_uses_structured_authoritative_data_and_records_usage(): void
    {
        $context = $this->stage13IntelligenceContext();
        $fake = $this->bindFakeAiProvider([new AiChatResult('One CRM item requires attention.', [], 20, 8, 3, 'test', 'test-chat', [], [], ['answer' => 'One CRM item requires attention.'], 12)]);
        OperationalPrioritySignal::factory()->for($context['company'])->create(['category' => 'CRM', 'source_module' => 'crm', 'explanation_metadata' => ['required_permission' => 'crm.view'], 'effective_at' => now()]);

        $this->getJson('/api/v1/ai/intelligence/briefing?period=TODAY&with_ai=1', $this->headers($context['company']->id))->assertOk()->assertJsonPath('status', 'AI_ENRICHED')->assertJsonPath('narrative', 'One CRM item requires attention.');

        $this->assertCount(1, $fake->requests);
        $this->assertStringContainsString('AUTHORITATIVE_SIGNALS', $fake->requests[0]->messages[0]['content']);
        $this->assertDatabaseHas('ai_usage_records', ['company_id' => $context['company']->id, 'operation' => 'MANAGEMENT_BRIEFING', 'cost_minor' => 3]);
    }

    public function test_briefing_excludes_signals_without_underlying_permission(): void
    {
        $context = $this->stage13IntelligenceContext(['intelligence.briefings.view', 'crm.view']);
        OperationalPrioritySignal::factory()->for($context['company'])->create(['category' => 'CRM', 'source_module' => 'crm', 'explanation_metadata' => ['required_permission' => 'crm.view'], 'effective_at' => now()]);
        OperationalPrioritySignal::factory()->for($context['company'])->create(['category' => 'PAYROLL', 'source_module' => 'payroll', 'explanation_metadata' => ['required_permission' => 'payroll.reports'], 'effective_at' => now()]);

        $response = $this->getJson('/api/v1/ai/intelligence/briefing?period=TODAY', $this->headers($context['company']->id))->assertOk();

        $this->assertSame(['CRM'], collect($response->json('structured_data.sections'))->pluck('category')->all());
        $this->assertStringNotContainsString('PAYROLL', json_encode($response->json(), JSON_THROW_ON_ERROR));
    }

    /** @return array<string,string> */
    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId];
    }
}
