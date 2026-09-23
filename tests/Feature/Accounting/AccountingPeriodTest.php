<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Journal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AccountingPeriodTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creates_non_overlapping_period_and_rejects_overlap_with_422(): void
    {
        [, $company] = $this->actingAsCompanyUser(['accounting.periods.manage']);

        $this->postJson('/api/v1/accounting/periods', ['name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30'], ['X-Company-Id' => $company->id])->assertCreated();
        $this->postJson('/api/v1/accounting/periods', ['name' => 'Overlap', 'start_date' => '2026-09-15', 'end_date' => '2026-10-15'], ['X-Company-Id' => $company->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');

        $this->assertDatabaseCount('accounting_periods', 1);
        $this->assertTrue(AuditLog::query()->where('company_id', $company->id)->where('action', 'create')->exists());
    }

    public function test_controlled_close_and_reopen_are_company_scoped_and_audited(): void
    {
        [, $company] = $this->actingAsCompanyUser(['accounting.periods.manage', 'accounting.period.close', 'accounting.period.reopen']);
        $period = AccountingPeriod::factory()->for($company)->create();

        $this->postJson('/api/v1/accounting/periods/'.$period->id.'/close', ['idempotency_key' => 'period-close'], ['X-Company-Id' => $company->id])
            ->assertCreated()
            ->assertJsonPath('status', 'closed');
        $this->postJson('/api/v1/accounting/periods/'.$period->id.'/reopen', ['reason' => 'Approved correction'], ['X-Company-Id' => $company->id])
            ->assertOk()
            ->assertJsonPath('status', 'reopened');

        $this->assertSame(2, AuditLog::query()->whereIn('action', ['close', 'reopen'])->count());
    }

    public function test_rejects_date_changes_after_period_contains_posted_journal_with_422(): void
    {
        [$user, $company] = $this->actingAsCompanyUser(['accounting.periods.manage']);
        $period = AccountingPeriod::factory()->for($company)->create();
        Journal::factory()->for($company)->create(['posting_date' => '2026-09-22', 'status' => 'posted', 'created_by' => $user->id, 'posted_by' => $user->id, 'posted_at' => now()]);

        $this->patchJson('/api/v1/accounting/periods/'.$period->id, ['start_date' => '2026-09-02', 'end_date' => '2026-09-30'], ['X-Company-Id' => $company->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
    }
}
