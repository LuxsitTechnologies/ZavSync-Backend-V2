<?php

namespace Tests\Feature;

use App\Models\CrmDeal;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Journal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmPipelineDealTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_configuration_is_company_specific_and_rejects_contradictory_stages(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $payload = ['name' => 'Enterprise', 'description' => 'Enterprise sales', 'is_active' => true, 'is_default' => false, 'stages' => [['name' => 'Discovery', 'position' => 1, 'probability_bps' => 2000, 'is_won' => false, 'is_lost' => false, 'is_active' => true], ['name' => 'Won', 'position' => 2, 'probability_bps' => 10000, 'is_won' => true, 'is_lost' => false, 'is_active' => true]]];
        $pipeline = $this->postJson('/api/v1/crm/pipelines', $payload, $headers)->assertSuccessful()->assertJsonCount(2, 'stages')->json();
        $this->getJson('/api/v1/crm/pipelines/'.$pipeline['id'], $headers)->assertSuccessful()->assertJsonPath('name', 'Enterprise');

        $payload['name'] = 'Invalid';
        $payload['stages'][0]['is_won'] = true;
        $payload['stages'][0]['is_lost'] = true;
        $this->postJson('/api/v1/crm/pipelines', $payload, $headers)->assertUnprocessable()->assertJsonValidationErrors('stages.0.is_lost');
    }

    public function test_deal_creation_uses_minor_units_and_stage_relationships(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $deal = $this->postJson('/api/v1/crm/deals', $this->dealPayload($context), $headers)->assertSuccessful()
            ->assertJsonPath('value', 12_345_67)->assertJsonPath('probabilityBasisPoints', 2500)->assertJsonPath('status', 'OPEN')->json();
        $this->assertDatabaseHas('crm_deals', ['id' => $deal['id'], 'amount' => 12_345_67, 'probability_bps' => 2500]);
        $this->assertSame(0, Journal::count());
    }

    public function test_deal_stage_transitions_are_authoritative_and_have_no_gl_effect(): void
    {
        $context = $this->stage9CrmContext();
        $deal = CrmDeal::factory()->for($context['company'])->for($context['account'], 'account')->for($context['pipeline'], 'pipeline')->for($context['stages']['open'], 'stage')->create(['primary_contact_id' => $context['contact']->id, 'amount' => 9_999_99, 'probability_bps' => 2500, 'status' => 'OPEN', 'created_by' => $context['user']->id]);
        $headers = $this->headers($context['company']->id);
        $this->postJson('/api/v1/crm/deals/'.$deal->id.'/transition', ['pipeline_stage_id' => $context['stages']['won']->id], $headers)
            ->assertSuccessful()->assertJsonPath('status', 'WON')->assertJsonPath('value', 9_999_99)->assertJsonPath('probabilityBasisPoints', 10000);

        $this->assertNotNull($deal->fresh()->closed_at);
        $this->assertSame(0, Journal::count());
        $this->putJson('/api/v1/crm/deals/'.$deal->id, $this->dealPayload($context), $headers)->assertStatus(409)->assertJsonPath('error_code', 'CRM_DEAL_CLOSED');
    }

    public function test_lost_transition_requires_reason_and_preserves_amount(): void
    {
        $context = $this->stage9CrmContext();
        $deal = CrmDeal::factory()->for($context['company'])->for($context['account'], 'account')->for($context['pipeline'], 'pipeline')->for($context['stages']['open'], 'stage')->create(['amount' => 7_500_00, 'status' => 'OPEN', 'created_by' => $context['user']->id]);
        $headers = $this->headers($context['company']->id);
        $this->postJson('/api/v1/crm/deals/'.$deal->id.'/transition', ['pipeline_stage_id' => $context['stages']['lost']->id], $headers)->assertUnprocessable()->assertJsonPath('error_code', 'CRM_LOSS_REASON_REQUIRED');
        $this->postJson('/api/v1/crm/deals/'.$deal->id.'/transition', ['pipeline_stage_id' => $context['stages']['lost']->id, 'loss_reason' => 'No budget'], $headers)->assertSuccessful()->assertJsonPath('status', 'LOST')->assertJsonPath('value', 7_500_00);
    }

    public function test_won_deal_customer_handoff_is_explicit_idempotent_and_does_not_create_invoice_or_journal(): void
    {
        $context = $this->stage9CrmContext();
        $deal = CrmDeal::factory()->for($context['company'])->for($context['account'], 'account')->for($context['pipeline'], 'pipeline')->for($context['stages']['won'], 'stage')->create(['status' => 'WON', 'closed_at' => now(), 'actual_close_date' => now(), 'created_by' => $context['user']->id]);
        $headers = $this->headers($context['company']->id);
        $payload = $this->customerPayload();
        $first = $this->postJson('/api/v1/crm/deals/'.$deal->id.'/customer-handoff', $payload, $headers)->assertSuccessful()->json();
        $second = $this->postJson('/api/v1/crm/deals/'.$deal->id.'/customer-handoff', $payload, $headers)->assertSuccessful()->json();

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, Customer::query()->where('company_id', $context['company']->id)->count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Journal::count());
    }

    public function test_open_deal_cannot_be_handed_off_and_mismatched_stage_is_rejected(): void
    {
        $context = $this->stage9CrmContext();
        $deal = CrmDeal::factory()->for($context['company'])->for($context['account'], 'account')->for($context['pipeline'], 'pipeline')->for($context['stages']['open'], 'stage')->create(['status' => 'OPEN', 'created_by' => $context['user']->id]);
        $headers = $this->headers($context['company']->id);
        $this->postJson('/api/v1/crm/deals/'.$deal->id.'/customer-handoff', $this->customerPayload(), $headers)->assertStatus(409)->assertJsonPath('error_code', 'CRM_DEAL_NOT_WON');

        $other = $this->postJson('/api/v1/crm/pipelines', ['name' => 'Other', 'stages' => [['name' => 'Other stage', 'position' => 1, 'probability_bps' => 1000]]], $headers)->assertSuccessful()->json();
        $payload = $this->dealPayload($context, ['pipeline_stage_id' => $other['stages'][0]['id']]);
        $this->postJson('/api/v1/crm/deals', $payload, $headers)->assertUnprocessable()->assertJsonPath('error_code', 'CRM_STAGE_PIPELINE_MISMATCH');
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function dealPayload(array $context, array $overrides = []): array
    {
        return [...['account_id' => $context['account']->id, 'primary_contact_id' => $context['contact']->id, 'lead_origin_id' => $context['lead']->id, 'pipeline_id' => $context['pipeline']->id, 'pipeline_stage_id' => $context['stages']['open']->id, 'owner_id' => $context['user']->id, 'title' => 'ERP Rollout', 'amount' => 12_345_67, 'currency' => 'PKR', 'probability_bps' => 2500, 'expected_close_date' => '2026-12-31', 'source' => 'Referral', 'description' => 'Commercial opportunity'], ...$overrides];
    }

    /** @return array<string, mixed> */
    private function customerPayload(): array
    {
        return ['idempotency_key' => 'won-deal-handoff', 'name' => 'Won Customer', 'type' => 'business', 'ntn' => '7654321', 'email' => 'won@example.com', 'country' => 'PK', 'payment_terms_days' => 30, 'currency' => 'PKR'];
    }

    /** @return array<string, string> */
    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId];
    }
}
