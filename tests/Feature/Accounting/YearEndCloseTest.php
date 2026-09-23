<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountMapping;
use App\Models\Journal;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YearEndCloseTest extends TestCase
{
    use RefreshDatabase;

    public function test_year_end_preview_blocks_incomplete_periods_and_missing_mapping(): void
    {
        $context = $this->stage7PlanningContext();
        $headers = $this->headers($context['company']->id);
        AccountMapping::query()->where('company_id', $context['company']->id)->where('key', 'retained_earnings')->delete();

        $preview = $this->getJson('/api/v1/accounting/fiscal-years/'.$context['fiscalYear']->id.'/year-end-preview', $headers)->assertOk()->assertJsonPath('ready', false)->assertJsonPath('retained_earnings_account', null);
        $this->assertContains('period_status', collect($preview->json('checks'))->where('passed', false)->pluck('key')->all());
        $this->assertContains('retained_earnings', collect($preview->json('checks'))->where('passed', false)->pluck('key')->all());
    }

    public function test_profit_close_posts_balanced_idempotent_journal_and_preserves_reports(): void
    {
        $context = $this->stage7PlanningContext();
        app(JournalPostingService::class)->post($context['company']->id, $context['user'], ['posting_date' => '2027-01-15', 'description' => 'Revenue', 'lines' => [['account_id' => $context['accounts']['cash']->id, 'debit' => 1000, 'credit' => 0], ['account_id' => $context['accounts']['revenue']->id, 'debit' => 0, 'credit' => 1000]]]);
        $this->closePeriods($context);
        $headers = $this->headers($context['company']->id);

        $this->getJson('/api/v1/accounting/fiscal-years/'.$context['fiscalYear']->id.'/year-end-preview', $headers)->assertOk()->assertJsonPath('ready', true)->assertJsonPath('net_profit', 1000)->assertJsonCount(2, 'closing_lines');
        $closed = $this->postJson('/api/v1/accounting/fiscal-years/'.$context['fiscalYear']->id.'/close', ['idempotency_key' => 'fy-close', 'confirmation' => 'CLOSE FY 2027 Q1'], $headers)->assertCreated()->assertJsonPath('status', 'closed');
        $this->postJson('/api/v1/accounting/fiscal-years/'.$context['fiscalYear']->id.'/close', ['idempotency_key' => 'fy-close', 'confirmation' => 'CLOSE FY 2027 Q1'], $headers)->assertOk()->assertJsonPath('id', $closed->json('id'));

        $journal = Journal::query()->where('source', 'year_end_close')->with('lines')->sole();
        $this->assertSame(1000, $journal->lines->sum('debit'));
        $this->assertSame(1000, $journal->lines->sum('credit'));
        $this->assertSame(1, Journal::query()->where('source', 'year_end_close')->count());
        $profitAndLoss = app(FinancialReportService::class)->profitAndLoss($context['company']->id, '2027-01-01', '2027-03-31');
        $balanceSheet = app(FinancialReportService::class)->balanceSheet($context['company']->id, '2027-03-31');
        $this->assertSame(1000, $profitAndLoss['net_profit']);
        $this->assertSame(0, $balanceSheet['current_earnings']);
        $this->assertTrue($balanceSheet['balanced']);
    }

    public function test_net_loss_closes_to_retained_earnings_and_reopen_uses_reversal(): void
    {
        $context = $this->stage7PlanningContext();
        app(JournalPostingService::class)->post($context['company']->id, $context['user'], ['posting_date' => '2027-02-15', 'description' => 'Expense', 'lines' => [['account_id' => $context['accounts']['expense']->id, 'debit' => 500, 'credit' => 0], ['account_id' => $context['accounts']['cash']->id, 'debit' => 0, 'credit' => 500]]]);
        $this->closePeriods($context);
        $headers = $this->headers($context['company']->id);

        $closed = $this->postJson('/api/v1/accounting/fiscal-years/'.$context['fiscalYear']->id.'/close', ['idempotency_key' => 'loss-close', 'confirmation' => 'CLOSE FY 2027 Q1'], $headers)->assertCreated();
        $journal = Journal::query()->findOrFail($closed->json('closing_journal_id'));
        $this->assertDatabaseHas('journal_lines', ['journal_id' => $journal->id, 'account_id' => $context['accounts']['retained_earnings']->id, 'debit' => 500, 'credit' => 0]);

        $this->postJson('/api/v1/accounting/fiscal-years/'.$context['fiscalYear']->id.'/reopen', ['reason' => 'Approved year-end correction'], $headers)->assertOk()->assertJsonPath('status', 'reopened');
        $this->assertSame('open', $context['fiscalYear']->fresh()->status);
        $this->assertSame('open', $context['periods'][2]->fresh()->status);
        $this->assertSame('reversed', $journal->fresh()->status);
        $this->assertDatabaseHas('accounting_close_records', ['id' => $closed->json('id'), 'status' => 'reopened']);
    }

    public function test_fiscal_year_supports_non_calendar_dates_and_rejects_overlap(): void
    {
        [, $company] = $this->actingAsCompanyUser(['budget.view', 'budget.manage']);
        $headers = $this->headers($company->id);

        $this->postJson('/api/v1/planning/fiscal-years', ['name' => 'FY 2028', 'start_date' => '2027-07-01', 'end_date' => '2028-06-30', 'currency' => 'PKR'], $headers)->assertCreated()->assertJsonPath('start_date', '2027-07-01');
        $this->postJson('/api/v1/planning/fiscal-years', ['name' => 'Overlap', 'start_date' => '2028-01-01', 'end_date' => '2028-12-31', 'currency' => 'PKR'], $headers)->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }

    /** @param array<string, mixed> $context */
    private function closePeriods(array $context): void
    {
        foreach ($context['periods'] as $index => $period) {
            $this->postJson("/api/v1/accounting/periods/{$period->id}/close", ['idempotency_key' => "period-close-{$index}"], $this->headers($context['company']->id))->assertCreated();
        }
    }

    private function headers(string $companyId): array
    {
        return ['X-Company-Id' => $companyId, 'Accept' => 'application/json'];
    }
}
