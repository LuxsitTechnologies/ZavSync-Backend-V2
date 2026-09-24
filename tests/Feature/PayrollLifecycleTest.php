<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\Company;
use App\Models\Journal;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_approval_and_posting_are_separate_controlled_events(): void
    {
        $context = $this->stage8PayrollContext();
        $batchId = $this->calculatedBatch($context);

        $this->assertDatabaseCount('journals', 0);
        $this->postJson("/api/v1/payroll/batches/{$batchId}/review", [], $this->headers($context['company']->id))->assertOk()->assertJsonPath('status', 'REVIEWED');
        $this->assertDatabaseCount('journals', 0);
        $this->postJson("/api/v1/payroll/batches/{$batchId}/approve", [], $this->headers($context['company']->id))->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->assertDatabaseCount('journals', 0);
        $this->postJson("/api/v1/payroll/batches/{$batchId}/post", [], $this->headers($context['company']->id, 'post-1'))->assertOk()->assertJsonPath('status', 'POSTED');
        $this->assertDatabaseCount('journals', 1);
        $this->assertDatabaseHas('audit_logs', ['module' => 'payroll', 'action' => 'approve']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'payroll', 'action' => 'post']);
    }

    public function test_posting_requires_approved_status_and_open_accounting_period(): void
    {
        $context = $this->stage8PayrollContext();
        $batchId = $this->calculatedBatch($context);

        $this->postJson("/api/v1/payroll/batches/{$batchId}/post", [], $this->headers($context['company']->id, 'early'))->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_BATCH_NOT_APPROVED');
        $this->postJson("/api/v1/payroll/batches/{$batchId}/review", [], $this->headers($context['company']->id))->assertOk();
        $this->postJson("/api/v1/payroll/batches/{$batchId}/approve", [], $this->headers($context['company']->id))->assertOk();
        $context['accountingPeriod']->update(['status' => 'closed']);
        $this->postJson("/api/v1/payroll/batches/{$batchId}/post", [], $this->headers($context['company']->id, 'closed'))->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_PERIOD_CLOSED');
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_posting_journal_balances_expenses_and_liabilities_without_crediting_bank(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);
        $journal = Journal::query()->with('lines')->findOrFail($batch->journal_id);

        $this->assertSame(1_140_000, (int) $journal->lines->sum('debit'));
        $this->assertSame(1_140_000, (int) $journal->lines->sum('credit'));
        $this->assertSame(1_100_000, (int) $journal->lines->where('account_id', $context['accounts']['salary_expense']->id)->sum('debit'));
        $this->assertSame(910_000, (int) $journal->lines->where('account_id', $context['accounts']['payroll_net_payable']->id)->sum('credit'));
        $this->assertSame(110_000, (int) $journal->lines->where('account_id', $context['accounts']['payroll_tax_payable']->id)->sum('credit'));
        $this->assertSame(30_000, (int) $journal->lines->where('account_id', $context['accounts']['payroll_employee_contribution_payable']->id)->sum('credit'));
        $this->assertSame(40_000, (int) $journal->lines->where('account_id', $context['accounts']['payroll_employer_contribution_payable']->id)->sum('credit'));
        $this->assertSame(0, (int) $journal->lines->where('account_id', $context['accounts']['bank']->id)->sum('credit'));
    }

    public function test_posting_is_idempotent_and_conflicting_retry_is_rejected(): void
    {
        $context = $this->stage8PayrollContext();
        $batchId = $this->approvedBatch($context);

        $first = $this->postJson("/api/v1/payroll/batches/{$batchId}/post", [], $this->headers($context['company']->id, 'retry-key'))->assertOk();
        $second = $this->postJson("/api/v1/payroll/batches/{$batchId}/post", [], $this->headers($context['company']->id, 'retry-key'))->assertOk();
        $this->assertSame($first->json('journal_id'), $second->json('journal_id'));
        $this->assertDatabaseCount('journals', 1);
        $this->postJson("/api/v1/payroll/batches/{$batchId}/post", [], $this->headers($context['company']->id, 'other-key'))->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_IDEMPOTENCY_CONFLICT');
    }

    public function test_posted_payroll_and_financial_components_are_immutable(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);
        $entry = PayrollEntry::query()->where('payroll_batch_id', $batch->id)->firstOrFail();

        $this->postJson("/api/v1/payroll/batches/{$batch->id}/recalculate", [], $this->headers($context['company']->id))->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_POSTED_IMMUTABLE');
        $this->postJson('/api/v1/payroll/entries/'.$entry->id.'/adjustments', ['payroll_component_id' => $context['components']['allowance']->id, 'amount' => 1, 'reason' => 'Attempted rewrite'], $this->headers($context['company']->id))->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_POSTED_IMMUTABLE');
    }

    public function test_missing_or_cross_company_mappings_roll_back_entire_posting(): void
    {
        $context = $this->stage8PayrollContext();
        $batchId = $this->approvedBatch($context);
        AccountMapping::query()->where('company_id', $context['company']->id)->where('key', 'salary_expense')->delete();

        $this->postJson("/api/v1/payroll/batches/{$batchId}/post", [], $this->headers($context['company']->id, 'missing-map'))->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_MAPPING_MISSING');
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseHas('payroll_batches', ['id' => $batchId, 'status' => 'APPROVED', 'journal_id' => null]);
    }

    public function test_cross_company_component_account_is_rejected_by_central_journal_service(): void
    {
        $context = $this->stage8PayrollContext();
        $foreign = Company::factory()->create();
        $foreignExpense = Account::factory()->for($foreign)->expense()->create(['created_by' => $context['user']->id]);
        $context['components']['allowance']->update(['gl_account_id' => $foreignExpense->id]);
        $batchId = $this->approvedBatch($context);

        $this->postJson("/api/v1/payroll/batches/{$batchId}/post", [], $this->headers($context['company']->id, 'foreign-gl'))->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_reversal_preserves_original_payroll_and_journal_history(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);

        $response = $this->postJson("/api/v1/payroll/batches/{$batch->id}/reverse", ['posting_date' => '2026-09-30', 'reason' => 'Approved correction'], $this->headers($context['company']->id, 'reverse-1'))->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->assertNotNull($response->json('reversal_journal_id'));
        $this->assertDatabaseHas('journals', ['id' => $batch->journal_id, 'status' => 'reversed']);
        $this->assertDatabaseHas('payroll_batches', ['id' => $batch->id, 'correction_reason' => 'Approved correction']);
        $this->assertDatabaseCount('payroll_entries', 1);
    }

    public function test_reversed_payroll_can_be_replaced_through_an_explicit_correction_relationship(): void
    {
        $context = $this->stage8PayrollContext();
        $batch = $this->postedBatch($context);
        $this->postJson("/api/v1/payroll/batches/{$batch->id}/reverse", ['posting_date' => '2026-09-30', 'reason' => 'Incorrect approved allowance'], $this->headers($context['company']->id, 'reverse-correction'))->assertOk();

        $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $context['payrollPeriod']->id], $this->headers($context['company']->id))->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_CORRECTION_INVALID');
        $replacement = $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $context['payrollPeriod']->id, 'correction_of_batch_id' => $batch->id], $this->headers($context['company']->id))->assertCreated();
        $replacement->assertJsonPath('status', 'DRAFT')->assertJsonPath('correction_of_batch_id', $batch->id);
        $this->assertDatabaseHas('payroll_batches', ['id' => $batch->id, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('payroll_batches', ['id' => $replacement->json('id'), 'correction_of_batch_id' => $batch->id]);
    }

    public function test_approval_and_posting_permissions_are_enforced(): void
    {
        $context = $this->stage8PayrollContext(['payroll.view', 'payroll.manage', 'payroll.calculate', 'payroll.review']);
        $batchId = $this->calculatedBatch($context);
        $this->postJson("/api/v1/payroll/batches/{$batchId}/review", [], $this->headers($context['company']->id))->assertOk();

        $this->postJson("/api/v1/payroll/batches/{$batchId}/approve", [], $this->headers($context['company']->id))->assertForbidden();
        PayrollBatch::query()->findOrFail($batchId)->update(['status' => 'APPROVED']);
        $this->postJson("/api/v1/payroll/batches/{$batchId}/post", [], $this->headers($context['company']->id, 'denied'))->assertForbidden();
    }

    /** @param array<string, mixed> $context */
    private function calculatedBatch(array $context): string
    {
        $batchId = $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $context['payrollPeriod']->id], $this->headers($context['company']->id))->assertCreated()->json('id');
        $this->postJson("/api/v1/payroll/batches/{$batchId}/calculate", [], $this->headers($context['company']->id))->assertOk();

        return $batchId;
    }

    /** @param array<string, mixed> $context */
    private function approvedBatch(array $context): string
    {
        $batchId = $this->calculatedBatch($context);
        $this->postJson("/api/v1/payroll/batches/{$batchId}/review", [], $this->headers($context['company']->id))->assertOk();
        $this->postJson("/api/v1/payroll/batches/{$batchId}/approve", [], $this->headers($context['company']->id))->assertOk();

        return $batchId;
    }

    /** @param array<string, mixed> $context */
    private function postedBatch(array $context): PayrollBatch
    {
        $batchId = $this->approvedBatch($context);
        $this->postJson("/api/v1/payroll/batches/{$batchId}/post", [], $this->headers($context['company']->id, 'post-'.$batchId))->assertOk();

        return PayrollBatch::query()->findOrFail($batchId);
    }

    /** @return array<string, string> */
    private function headers(string $companyId, ?string $idempotencyKey = null): array
    {
        return array_filter(['X-Company-Id' => $companyId, 'Accept' => 'application/json', 'Idempotency-Key' => $idempotencyKey]);
    }
}
