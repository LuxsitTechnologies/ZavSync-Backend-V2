<?php

namespace Tests\Feature\Stage12;

use App\Models\AiConversation;
use App\Models\Customer;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Services\Ai\AiChatResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAccountingFirewallTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_copilot_lifecycle_creates_no_financial_or_operational_posting(): void
    {
        $context = $this->stage12AiContext();
        $this->bindFakeAiProvider([
            new AiChatResult('', [['name' => 'accounting.trial_balance', 'arguments' => []]], 10, 2, 1, 'test', 'test-chat'),
            new AiChatResult('I found no posted balance and prepared a follow-up task for approval. [1]', [], 12, 9, 1, 'test', 'test-chat', [1], [['action_type' => 'CRM_ACTIVITY_DRAFT', 'payload' => ['type' => 'TASK', 'subject' => 'Review close position', 'priority' => 'HIGH']]]),
        ]);
        $source = KnowledgeSource::factory()->for($context['company'])->create(['created_by' => $context['user']->id, 'status' => 'READY', 'indexed_at' => now(), 'access_permission' => 'accounting.view']);
        KnowledgeChunk::factory()->for($context['company'])->for($source, 'source')->create(['content' => 'The close checklist requires reviewing the posted trial balance.', 'embedding' => [100, 50, 25]]);
        $conversation = AiConversation::factory()->for($context['company'])->for($context['user'])->create();

        $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['content' => 'Review the close position and prepare a follow-up.'], $this->headers($context['company']->id, 'firewall-lifecycle'))->assertCreated()->assertJsonPath('status', 'COMPLETED');

        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('journal_lines', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('customer_payments', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('payroll_batches', 0);
        $this->assertDatabaseCount('crm_activities', 0);
        $this->assertDatabaseHas('ai_action_proposals', ['company_id' => $context['company']->id, 'status' => 'PENDING']);
    }

    public function test_approved_invoice_proposal_creates_only_a_draft_without_gl_ar_payment_or_fbr_effect(): void
    {
        $context = $this->stage12AiContext();
        $customer = Customer::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        $payload = [
            'action_type' => 'INVOICE_DRAFT',
            'payload' => [
                'customer_id' => $customer->id,
                'invoice_date' => '2026-09-25',
                'due_date' => '2026-10-25',
                'currency' => 'PKR',
                'lines' => [['description' => 'Advisory service', 'quantity_milli' => 1000, 'unit' => 'service', 'unit_price' => 50_000, 'discount' => 0, 'tax_rate_bps' => 0, 'sales_type' => 'Services']],
            ],
        ];
        $proposalId = $this->postJson('/api/v1/ai/action-proposals', $payload, $this->headers($context['company']->id, 'firewall-invoice'))->assertCreated()->json('id');

        $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/approve", [], $this->headers($context['company']->id))->assertOk();
        $this->postJson("/api/v1/ai/action-proposals/{$proposalId}/execute", [], $this->headers($context['company']->id, 'firewall-invoice-execute'))->assertOk();

        $this->assertDatabaseHas('invoices', ['company_id' => $context['company']->id, 'status' => 'draft', 'journal_id' => null]);
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('journal_lines', 0);
        $this->assertDatabaseCount('customer_payments', 0);
        $this->assertDatabaseCount('fbr_submission_attempts', 0);
    }

    /** @return array<string, string> */
    private function headers(string $companyId, ?string $key = null): array
    {
        return array_filter(['X-Company-Id' => $companyId, 'Idempotency-Key' => $key]);
    }
}
