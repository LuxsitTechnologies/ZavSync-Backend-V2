<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingCloseRecord;
use App\Models\Journal;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountingCloseTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_identifies_draft_journal_blocker(): void
    {
        $context = $this->stage7PlanningContext();
        Journal::factory()->for($context['company'])->create(['posting_date' => '2027-01-15', 'status' => 'draft', 'created_by' => $context['user']->id]);

        $this->getJson('/api/v1/accounting/periods/'.$context['periods'][0]->id.'/readiness', $this->headers($context['company']->id))->assertOk()->assertJsonPath('ready', false)->assertJsonPath('blocker_count', 1)->assertJsonPath('checks.1.key', 'draft_journals')->assertJsonPath('checks.1.passed', false);
        $this->postJson('/api/v1/accounting/periods/'.$context['periods'][0]->id.'/close', ['idempotency_key' => 'blocked-close'], $this->headers($context['company']->id))->assertUnprocessable()->assertJsonValidationErrors('period');
    }

    public function test_period_close_is_idempotent_locks_posting_and_preserves_reopen_history(): void
    {
        $context = $this->stage7PlanningContext();
        $period = $context['periods'][0];
        $headers = $this->headers($context['company']->id);

        $first = $this->postJson("/api/v1/accounting/periods/{$period->id}/close", ['idempotency_key' => 'jan-close'], $headers)->assertCreated()->assertJsonPath('status', 'closed');
        $this->postJson("/api/v1/accounting/periods/{$period->id}/close", ['idempotency_key' => 'jan-close'], $headers)->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->assertSame('closed', $period->fresh()->status);

        try {
            app(JournalPostingService::class)->post($context['company']->id, $context['user'], ['posting_date' => '2027-01-20', 'description' => 'Rejected', 'lines' => [['account_id' => $context['accounts']['expense']->id, 'debit' => 100, 'credit' => 0], ['account_id' => $context['accounts']['cash']->id, 'debit' => 0, 'credit' => 100]]]);
            $this->fail('Posting in a closed period must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('posting_date', $exception->errors());
        }

        $this->postJson("/api/v1/accounting/periods/{$period->id}/reopen", ['reason' => 'Approved correction required'], $headers)->assertOk()->assertJsonPath('status', 'reopened')->assertJsonPath('reason', 'Approved correction required');
        $this->assertSame('open', $period->fresh()->status);
        $this->assertDatabaseHas('accounting_close_records', ['id' => $first->json('id'), 'status' => 'reopened', 'reason' => 'Approved correction required']);
    }

    public function test_reopen_requires_permission_reason_and_company_scope(): void
    {
        $context = $this->stage7PlanningContext();
        $period = $context['periods'][0];
        $headers = $this->headers($context['company']->id);
        $this->postJson("/api/v1/accounting/periods/{$period->id}/close", ['idempotency_key' => 'close-rbac'], $headers)->assertCreated();

        $this->postJson("/api/v1/accounting/periods/{$period->id}/reopen", ['reason' => 'no'], $headers)->assertUnprocessable()->assertJsonValidationErrors('reason');
        [, $otherCompany] = $this->actingAsCompanyUser(['accounting.period.reopen']);
        $this->postJson("/api/v1/accounting/periods/{$period->id}/reopen", ['reason' => 'Foreign correction'], $this->headers($otherCompany->id))->assertNotFound();
    }

    public function test_close_record_factory_is_valid(): void
    {
        $context = $this->stage7PlanningContext();
        $record = AccountingCloseRecord::factory()->create(['company_id' => $context['company']->id, 'accounting_period_id' => $context['periods'][0]->id, 'closed_by' => $context['user']->id]);

        $this->assertSame($context['company']->id, $record->company_id);
    }

    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId, 'Accept' => 'application/json'];
    }
}
