<?php

namespace App\Services\Accounting;

use App\Models\AccountingCloseRecord;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\BankReconciliation;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\FbrSubmissionAttempt;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\PayrollBatch;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\Inventory\InventoryReportingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AccountingCloseService
{
    public function __construct(
        private readonly FinancialReportService $reports,
        private readonly InventoryReportingService $inventoryReports,
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /** @return array<string, mixed> */
    public function periodReadiness(string $companyId, AccountingPeriod $period): array
    {
        $period = AccountingPeriod::query()->where('company_id', $companyId)->findOrFail($period->id);
        $from = $period->start_date->format('Y-m-d');
        $to = $period->end_date->format('Y-m-d');
        $trial = $this->reports->trialBalance($companyId, $from, $to);
        $inventory = $this->inventoryReports->reconciliation($companyId, ['as_of' => $to]);
        $approvedUnpostedPayroll = PayrollBatch::query()->where('company_id', $companyId)->where('status', 'APPROVED')->whereBetween('accounting_date', [$from, $to])->count();
        $payrollIntegrityFailures = PayrollBatch::query()->where('company_id', $companyId)->whereIn('status', ['POSTED', 'PARTIALLY_PAID', 'PAID'])->whereBetween('accounting_date', [$from, $to])->whereNull('journal_id')->count();
        $outstandingPayrollLiabilities = PayrollBatch::query()->where('company_id', $companyId)->whereIn('status', ['POSTED', 'PARTIALLY_PAID'])->whereBetween('accounting_date', [$from, $to])->count();
        $checks = collect([
            $this->check('trial_balance', 'Trial balance integrity', $trial['balanced'] ? 'INFORMATION' : 'BLOCKER', $trial['balanced'] ? 'Trial balance is balanced.' : 'Trial balance is not balanced.', $trial['difference'] ?? ($trial['debit'] - $trial['credit'])),
            $this->countCheck('draft_journals', 'Draft journals', Journal::query()->where('company_id', $companyId)->where('status', 'draft')->whereBetween('posting_date', [$from, $to])->count(), 'BLOCKER'),
            $this->countCheck('unreconciled_bank_transactions', 'Unreconciled banking activity', BankTransaction::query()->where('company_id', $companyId)->whereBetween('transaction_date', [$from, $to])->whereIn('status', ['unmatched', 'suggested', 'partially_matched'])->count(), 'BLOCKER'),
            $this->countCheck('incomplete_bank_reconciliations', 'Incomplete bank reconciliations', BankReconciliation::query()->where('company_id', $companyId)->whereDate('period_start', '<=', $to)->whereDate('period_end', '>=', $from)->where('status', '!=', 'completed')->count(), 'BLOCKER'),
            $this->check('inventory_gl', 'Inventory and GL reconciliation', $inventory['difference'] === 0 ? 'INFORMATION' : 'BLOCKER', $inventory['explanation'], (int) $inventory['difference']),
            $this->countCheck('integration_failures', 'Accounting integration failures', FbrSubmissionAttempt::query()->where('company_id', $companyId)->where('status', 'failed')->whereHas('invoice', fn ($query) => $query->whereBetween('invoice_date', [$from, $to]))->count(), 'WARNING'),
            $this->countCheck('outstanding_ar', 'Outstanding accounts receivable', Invoice::query()->where('company_id', $companyId)->where('balance_due', '>', 0)->count(), 'INFORMATION'),
            $this->countCheck('outstanding_ap', 'Outstanding accounts payable', SupplierBill::query()->where('company_id', $companyId)->where('balance_due', '>', 0)->count(), 'INFORMATION'),
            $this->countCheck('approved_unposted_payroll', 'Approved payroll awaiting posting', $approvedUnpostedPayroll, 'BLOCKER'),
            $this->countCheck('payroll_integrity', 'Payroll posting integrity', $payrollIntegrityFailures, 'BLOCKER'),
            $this->countCheck('outstanding_payroll_liabilities', 'Outstanding payroll liabilities', $outstandingPayrollLiabilities, 'INFORMATION'),
        ]);

        return ['period_id' => $period->id, 'status' => $period->status, 'ready' => ! $checks->contains(fn (array $check): bool => $check['severity'] === 'BLOCKER' && ! $check['passed']), 'checks' => $checks->all(), 'blocker_count' => $checks->where('severity', 'BLOCKER')->where('passed', false)->count(), 'warning_count' => $checks->where('severity', 'WARNING')->where('passed', false)->count()];
    }

    public function closePeriod(string $companyId, User $user, AccountingPeriod $period, string $idempotencyKey): AccountingCloseRecord
    {
        return DB::transaction(function () use ($companyId, $user, $period, $idempotencyKey): AccountingCloseRecord {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode(['period_id' => $period->id], JSON_THROW_ON_ERROR));
            $existing = AccountingCloseRecord::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->idempotency_hash, $hash)) {
                    throw new ConflictHttpException('The idempotency key has already been used for another close request.');
                }

                return $existing;
            }
            $period = AccountingPeriod::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($period->id);
            if ($period->status === 'closed') {
                $record = AccountingCloseRecord::query()->where('company_id', $companyId)->where('accounting_period_id', $period->id)->where('status', 'closed')->latest()->first();
                if ($record !== null) {
                    return $record;
                }
            }
            $readiness = $this->periodReadiness($companyId, $period);
            if (! $readiness['ready']) {
                throw ValidationException::withMessages(['period' => 'The accounting period has unresolved close blockers.', 'checks' => $readiness['checks']]);
            }
            $period->update(['status' => 'closed', 'closed_by' => $user->id, 'closed_at' => now()]);

            return AccountingCloseRecord::query()->create(['company_id' => $companyId, 'close_type' => 'period', 'accounting_period_id' => $period->id, 'fiscal_year_id' => $period->fiscal_year_id, 'status' => 'closed', 'checklist_snapshot' => $readiness, 'idempotency_key' => $idempotencyKey, 'idempotency_hash' => $hash, 'closed_by' => $user->id, 'closed_at' => now()]);
        });
    }

    public function reopenPeriod(string $companyId, User $user, AccountingPeriod $period, string $reason): AccountingCloseRecord
    {
        return DB::transaction(function () use ($companyId, $user, $period, $reason): AccountingCloseRecord {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $period = AccountingPeriod::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($period->id);
            if ($period->status !== 'closed') {
                throw ValidationException::withMessages(['period' => 'Only a closed period can be reopened.']);
            }
            if ($period->fiscal_year_id !== null && FiscalYear::query()->where('company_id', $companyId)->whereKey($period->fiscal_year_id)->where('status', 'closed')->exists()) {
                throw ValidationException::withMessages(['period' => 'Reopen the fiscal year through the controlled year-end workflow.']);
            }
            $record = AccountingCloseRecord::query()->where('company_id', $companyId)->where('accounting_period_id', $period->id)->where('status', 'closed')->lockForUpdate()->latest()->firstOrFail();
            $period->update(['status' => 'open', 'closed_by' => null, 'closed_at' => null]);
            $record->update(['status' => 'reopened', 'reason' => $reason, 'reopened_by' => $user->id, 'reopened_at' => now()]);

            return $record->fresh();
        });
    }

    /** @return array<string, mixed> */
    public function yearEndPreview(string $companyId, FiscalYear $year): array
    {
        $year = FiscalYear::query()->where('company_id', $companyId)->with('periods')->findOrFail($year->id);
        $periodsValid = $this->fiscalPeriodsAreComplete($year);
        $allClosed = $year->periods->isNotEmpty() && $year->periods->every(fn (AccountingPeriod $period): bool => $period->status === 'closed');
        $trial = $this->reports->trialBalance($companyId, $year->start_date->format('Y-m-d'), $year->end_date->format('Y-m-d'));
        $profitAndLoss = $this->reports->profitAndLoss($companyId, $year->start_date->format('Y-m-d'), $year->end_date->format('Y-m-d'));
        $mapping = AccountMapping::query()->where('company_id', $companyId)->where('key', 'retained_earnings')->with('account')->first();
        $retainedEarnings = $mapping?->account;
        $validMapping = $retainedEarnings !== null && $retainedEarnings->is_active && $retainedEarnings->type === 'equity';
        $draftJournals = Journal::query()->where('company_id', $companyId)->where('status', 'draft')->whereBetween('posting_date', [$year->start_date, $year->end_date])->count();
        $unreconciled = BankTransaction::query()->where('company_id', $companyId)->whereBetween('transaction_date', [$year->start_date, $year->end_date])->whereIn('status', ['unmatched', 'suggested', 'partially_matched'])->count();
        $incompleteReconciliations = BankReconciliation::query()->where('company_id', $companyId)->whereDate('period_start', '<=', $year->end_date)->whereDate('period_end', '>=', $year->start_date)->where('status', '!=', 'completed')->count();
        $inventory = $this->inventoryReports->reconciliation($companyId, ['as_of' => $year->end_date->format('Y-m-d')]);
        $checks = collect([
            $this->check('period_coverage', 'Fiscal period coverage', $periodsValid ? 'INFORMATION' : 'BLOCKER', $periodsValid ? 'Periods cover the fiscal year without gaps.' : 'Fiscal periods must cover the complete fiscal year without gaps.'),
            $this->check('period_status', 'Fiscal periods closed', $allClosed ? 'INFORMATION' : 'BLOCKER', $allClosed ? 'All fiscal periods are closed.' : 'Every fiscal period must be closed before year-end.'),
            $this->check('trial_balance', 'Year-end trial balance', $trial['balanced'] ? 'INFORMATION' : 'BLOCKER', $trial['balanced'] ? 'Trial balance is balanced.' : 'Trial balance is not balanced.'),
            $this->check('retained_earnings', 'Retained earnings mapping', $validMapping ? 'INFORMATION' : 'BLOCKER', $validMapping ? 'Retained earnings mapping is valid.' : 'Configure an active equity retained earnings account.'),
            $this->countCheck('draft_journals', 'Draft journals', $draftJournals, 'BLOCKER'),
            $this->countCheck('unreconciled_bank_transactions', 'Unreconciled banking activity', $unreconciled, 'BLOCKER'),
            $this->countCheck('incomplete_bank_reconciliations', 'Incomplete bank reconciliations', $incompleteReconciliations, 'BLOCKER'),
            $this->check('inventory_gl', 'Inventory and GL reconciliation', $inventory['difference'] === 0 ? 'INFORMATION' : 'BLOCKER', $inventory['explanation'], (int) $inventory['difference']),
        ]);
        $closingLines = $validMapping ? $this->closingLines($profitAndLoss['rows'], $retainedEarnings->id, (int) $profitAndLoss['net_profit']) : [];

        return ['fiscal_year_id' => $year->id, 'ready' => ! $checks->contains(fn (array $check): bool => $check['severity'] === 'BLOCKER' && ! $check['passed']), 'checks' => $checks->all(), 'trial_balance' => ['debit' => $trial['debit'], 'credit' => $trial['credit'], 'balanced' => $trial['balanced']], 'profit_and_loss' => $profitAndLoss, 'net_profit' => $profitAndLoss['net_profit'], 'retained_earnings_account' => $validMapping ? ['id' => $retainedEarnings->id, 'code' => $retainedEarnings->code, 'name' => $retainedEarnings->name] : null, 'closing_lines' => $closingLines];
    }

    public function closeFiscalYear(string $companyId, User $user, FiscalYear $year, string $idempotencyKey, string $confirmation): AccountingCloseRecord
    {
        return DB::transaction(function () use ($companyId, $user, $year, $idempotencyKey, $confirmation): AccountingCloseRecord {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $year = FiscalYear::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($year->id);
            if ($confirmation !== 'CLOSE '.$year->name) {
                throw ValidationException::withMessages(['confirmation' => 'Type CLOSE '.$year->name.' to confirm year-end close.']);
            }
            $hash = hash('sha256', json_encode(['fiscal_year_id' => $year->id], JSON_THROW_ON_ERROR));
            $existing = AccountingCloseRecord::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->idempotency_hash, $hash)) {
                    throw new ConflictHttpException('The idempotency key has already been used for another close request.');
                }

                return $existing;
            }
            if ($year->status === 'closed') {
                throw ValidationException::withMessages(['fiscal_year' => 'The fiscal year is already closed.']);
            }
            $preview = $this->yearEndPreview($companyId, $year);
            if (! $preview['ready']) {
                throw ValidationException::withMessages(['fiscal_year' => 'The fiscal year has unresolved close blockers.', 'checks' => $preview['checks']]);
            }
            $journal = null;
            if ($preview['closing_lines'] !== []) {
                $journal = $this->journalPostingService->postYearEndClosing($companyId, $user, ['posting_date' => $year->end_date->format('Y-m-d'), 'reference' => $year->name, 'reference_type' => 'fiscal_year', 'source_id' => $year->id, 'source' => 'year_end_close', 'description' => 'Year-end closing entry for '.$year->name, 'lines' => $preview['closing_lines']], 'year-close-'.$year->id);
            }
            $year->update(['status' => 'closed', 'closed_by' => $user->id, 'closed_at' => now(), 'reopened_by' => null, 'reopened_at' => null, 'reopen_reason' => null]);

            return AccountingCloseRecord::query()->create(['company_id' => $companyId, 'close_type' => 'fiscal_year', 'fiscal_year_id' => $year->id, 'status' => 'closed', 'checklist_snapshot' => $preview, 'closing_journal_id' => $journal?->id, 'idempotency_key' => $idempotencyKey, 'idempotency_hash' => $hash, 'closed_by' => $user->id, 'closed_at' => now()]);
        });
    }

    public function reopenFiscalYear(string $companyId, User $user, FiscalYear $year, string $reason): AccountingCloseRecord
    {
        return DB::transaction(function () use ($companyId, $user, $year, $reason): AccountingCloseRecord {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $year = FiscalYear::query()->where('company_id', $companyId)->with('periods')->lockForUpdate()->findOrFail($year->id);
            if ($year->status !== 'closed') {
                throw ValidationException::withMessages(['fiscal_year' => 'Only a closed fiscal year can be reopened.']);
            }
            $record = AccountingCloseRecord::query()->where('company_id', $companyId)->where('fiscal_year_id', $year->id)->where('close_type', 'fiscal_year')->where('status', 'closed')->with('closingJournal')->lockForUpdate()->latest()->firstOrFail();
            $finalPeriod = $year->periods->sortByDesc('end_date')->first();
            $finalPeriod?->update(['status' => 'open', 'closed_by' => null, 'closed_at' => null]);
            $reversal = $record->closingJournal !== null ? $this->journalPostingService->reverse($companyId, $user, $record->closingJournal, $year->end_date->format('Y-m-d'), $reason, 'year-reopen-'.$year->id) : null;
            $year->update(['status' => 'open', 'reopened_by' => $user->id, 'reopened_at' => now(), 'reopen_reason' => $reason]);
            $record->update(['status' => 'reopened', 'reason' => $reason, 'reversal_journal_id' => $reversal?->id, 'reopened_by' => $user->id, 'reopened_at' => now()]);

            return $record->fresh();
        });
    }

    private function fiscalPeriodsAreComplete(FiscalYear $year): bool
    {
        $periods = $year->periods->sortBy('start_date')->values();
        if ($periods->isEmpty() || ! $periods->first()->start_date->isSameDay($year->start_date) || ! $periods->last()->end_date->isSameDay($year->end_date)) {
            return false;
        }
        for ($index = 1; $index < $periods->count(); $index++) {
            if (! $periods[$index - 1]->end_date->addDay()->isSameDay($periods[$index]->start_date)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private function closingLines(array $rows, string $retainedEarningsId, int $netProfit): array
    {
        $lines = [];
        foreach ($rows as $row) {
            $amount = (int) $row['amount'];
            if ($amount === 0) {
                continue;
            }
            $lines[] = ['account_id' => $row['account_id'], 'description' => 'Close '.$row['account_name'], 'debit' => $row['type'] === 'revenue' ? max($amount, 0) : max(-$amount, 0), 'credit' => $row['type'] === 'expense' ? max($amount, 0) : max(-$amount, 0)];
        }
        if ($netProfit !== 0) {
            $lines[] = ['account_id' => $retainedEarningsId, 'description' => 'Transfer current-year earnings', 'debit' => $netProfit < 0 ? abs($netProfit) : 0, 'credit' => $netProfit > 0 ? $netProfit : 0];
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    private function check(string $key, string $label, string $severity, string $message, int|string|null $value = null): array
    {
        return ['key' => $key, 'label' => $label, 'severity' => $severity, 'passed' => $severity === 'INFORMATION' || $value === 0, 'message' => $message, 'value' => $value];
    }

    /** @return array<string, mixed> */
    private function countCheck(string $key, string $label, int $count, string $severity): array
    {
        return ['key' => $key, 'label' => $label, 'severity' => $severity, 'passed' => $count === 0 || $severity === 'INFORMATION', 'message' => $count === 0 ? 'No exceptions found.' : "{$count} item(s) require review.", 'value' => $count];
    }
}
