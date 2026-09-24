<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\CrmScoreEvent;
use App\Models\CrmTag;
use App\Models\Journal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmActivityTagScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_create_filter_complete_reopen_and_timeline_are_persisted_without_accounting(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $activity = $this->postJson('/api/v1/crm/activities', $this->activityPayload($context), $headers)->assertSuccessful()->assertJsonPath('status', 'PENDING')->json();
        $this->getJson('/api/v1/crm/activities?type=TASK&status=PENDING&mine=1&overdue=1', $headers)->assertSuccessful()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/crm/activities/'.$activity['id'].'/transition', ['status' => 'COMPLETED', 'outcome' => 'Follow-up sent'], $headers)->assertSuccessful()->assertJsonPath('status', 'COMPLETED');
        $this->assertNotNull(CrmActivity::query()->findOrFail($activity['id'])->completed_at);
        $this->postJson('/api/v1/crm/activities/'.$activity['id'].'/transition', ['status' => 'PENDING'], $headers)->assertSuccessful()->assertJsonPath('status', 'PENDING');
        $this->assertSame(0, Journal::count());
    }

    public function test_activity_rejects_cross_company_related_entity_and_owner(): void
    {
        $context = $this->stage9CrmContext();
        $foreign = CrmLead::factory()->for(Company::factory()->create())->create();
        $payload = $this->activityPayload($context, ['related_id' => $foreign->id]);

        $this->postJson('/api/v1/crm/activities', $payload, $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors('related_id');
    }

    public function test_company_tags_can_be_created_and_attached_but_cross_company_tags_cannot(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $tag = $this->postJson('/api/v1/crm/tags', ['name' => 'Enterprise', 'color' => '#2563eb'], $headers)->assertSuccessful()->json();
        $this->postJson('/api/v1/crm/tags/attach', ['entity_type' => 'lead', 'entity_id' => $context['lead']->id, 'tag_ids' => [$tag['id']]], $headers)->assertSuccessful()->assertJsonCount(1);
        $this->assertDatabaseHas('crm_taggables', ['company_id' => $context['company']->id, 'crm_tag_id' => $tag['id'], 'taggable_id' => $context['lead']->id]);

        $foreignTag = CrmTag::factory()->for(Company::factory()->create())->create();
        $this->postJson('/api/v1/crm/tags/attach', ['entity_type' => 'lead', 'entity_id' => $context['lead']->id, 'tag_ids' => [$foreignTag->id]], $headers)->assertUnprocessable()->assertJsonValidationErrors('tag_ids.0');
    }

    public function test_scoring_is_deterministic_explainable_recalculable_and_accounting_neutral(): void
    {
        $context = $this->stage9CrmContext();
        $headers = $this->headers($context['company']->id);
        $ruleOne = $this->postJson('/api/v1/crm/score-rules', $this->scoreRule(['name' => 'Email present', 'field' => 'email', 'operator' => 'NOT_EMPTY', 'points' => 20, 'position' => 1]), $headers)->assertSuccessful()->json();
        $this->postJson('/api/v1/crm/score-rules', $this->scoreRule(['name' => 'Qualified status', 'field' => 'status', 'operator' => 'EQUALS', 'comparison_value' => 'QUALIFIED', 'points' => 60, 'position' => 2]), $headers)->assertSuccessful();
        $result = $this->postJson('/api/v1/crm/scores/lead/'.$context['lead']->id.'/recalculate', [], $headers)->assertSuccessful()->json('data');

        $this->assertSame(80, $result['score']);
        $this->assertCount(2, $result['events']);
        $this->assertSame(80, $context['lead']->fresh()->score);
        $this->assertSame(80, CrmScoreEvent::query()->where('scoreable_id', $context['lead']->id)->sum('points'));
        $this->assertSame(0, Journal::count());

        $this->putJson('/api/v1/crm/score-rules/'.$ruleOne['id'], $this->scoreRule(['name' => 'Email present', 'field' => 'email', 'operator' => 'NOT_EMPTY', 'points' => 30, 'position' => 1]), $headers)->assertSuccessful();
        $this->postJson('/api/v1/crm/scores/lead/'.$context['lead']->id.'/recalculate', [], $headers)->assertSuccessful()->assertJsonPath('data.score', 90);
    }

    public function test_scoring_rules_and_targets_are_tenant_isolated(): void
    {
        $context = $this->stage9CrmContext();
        $foreign = CrmLead::factory()->for(Company::factory()->create())->create();
        $this->postJson('/api/v1/crm/scores/lead/'.$foreign->id.'/recalculate', [], $this->headers($context['company']->id))->assertNotFound();
        $this->getJson('/api/v1/crm/score-rules', $this->headers($context['company']->id))->assertSuccessful()->assertJsonCount(0);
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function activityPayload(array $context, array $overrides = []): array
    {
        return [...['related_type' => 'lead', 'related_id' => $context['lead']->id, 'owner_id' => $context['user']->id, 'type' => 'TASK', 'subject' => 'Send proposal', 'description' => 'Share the commercial proposal.', 'due_at' => now()->subDay()->toISOString(), 'status' => 'PENDING', 'priority' => 'HIGH'], ...$overrides];
    }

    /** @return array<string, mixed> */
    private function scoreRule(array $overrides = []): array
    {
        return [...['name' => 'Scoring rule', 'target_type' => 'LEAD', 'field' => 'email', 'operator' => 'NOT_EMPTY', 'comparison_value' => null, 'points' => 10, 'position' => 1, 'is_active' => true], ...$overrides];
    }

    /** @return array<string, string> */
    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId];
    }
}
