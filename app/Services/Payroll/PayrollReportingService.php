<?php

namespace App\Services\Payroll;

use App\Models\JournalLine;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use Illuminate\Database\Eloquent\Builder;

class PayrollReportingService
{
    /** @return array<string, mixed> */
    public function register(string $companyId, PayrollBatch $batch): array
    {
        $batch = PayrollBatch::query()->where('company_id', $companyId)->with(['period', 'entries.lines', 'entries.paymentAllocations'])->findOrFail($batch->id);

        return ['batch' => $this->batchSummary($batch), 'entries' => $batch->entries->map(fn (PayrollEntry $entry): array => $this->entrySummary($entry))->all()];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function summary(string $companyId, array $filters = []): array
    {
        $query = PayrollBatch::query()->where('company_id', $companyId)->with('period')->orderByDesc('accounting_date');
        $this->dateFilters($query, $filters);
        $batches = $query->get();

        return ['totals' => ['batches' => $batches->count(), 'employees' => (int) $batches->sum('employee_count'), 'gross_earnings' => (int) $batches->sum('gross_earnings'), 'tax_amount' => (int) $batches->sum('tax_amount'), 'employee_deductions' => (int) $batches->sum('employee_deductions'), 'employee_contributions' => (int) $batches->sum('employee_contributions'), 'employer_contributions' => (int) $batches->sum('employer_contributions'), 'net_pay' => (int) $batches->sum('net_pay'), 'employer_total_cost' => (int) $batches->sum('employer_total_cost')], 'batches' => $batches->map(fn (PayrollBatch $batch): array => $this->batchSummary($batch))->all()];
    }

    /** @return array<string, mixed> */
    public function liabilities(string $companyId, ?string $batchId = null): array
    {
        $entries = PayrollEntry::query()->where('company_id', $companyId)->whereHas('batch', fn ($query) => $query->whereIn('status', ['POSTED', 'PARTIALLY_PAID', 'PAID']))->when($batchId, fn ($query) => $query->where('payroll_batch_id', $batchId))->with(['batch.period', 'paymentAllocations', 'lines.settlementAllocations'])->get();
        $rows = [];
        foreach ($entries as $entry) {
            $netOutstanding = $entry->net_pay - (int) $entry->paymentAllocations->sum('amount');
            if ($netOutstanding > 0) {
                $rows[] = ['batch_id' => $entry->payroll_batch_id, 'batch_number' => $entry->batch->number, 'period' => $entry->batch->period->name, 'employee_id' => $entry->employee_id, 'employee_name' => $entry->employee_name, 'liability_type' => 'NET_PAY', 'original_amount' => $entry->net_pay, 'settled_amount' => $entry->net_pay - $netOutstanding, 'outstanding_amount' => $netOutstanding];
            }
            foreach ($entry->lines as $line) {
                $liabilityType = match ($line->component_type) {
                    'TAX' => 'TAX',
                    'EMPLOYEE_CONTRIBUTIONS' => 'EMPLOYEE_CONTRIBUTION',
                    'EMPLOYER_CONTRIBUTIONS' => 'EMPLOYER_CONTRIBUTION',
                    'DEDUCTIONS' => 'OTHER_DEDUCTION',
                    default => null,
                };
                if ($liabilityType === null) {
                    continue;
                }
                $settled = (int) $line->settlementAllocations->sum('amount');
                if ($line->amount > $settled) {
                    $rows[] = ['batch_id' => $entry->payroll_batch_id, 'batch_number' => $entry->batch->number, 'period' => $entry->batch->period->name, 'employee_id' => $entry->employee_id, 'employee_name' => $entry->employee_name, 'liability_type' => $liabilityType, 'component' => $line->component_name, 'original_amount' => $line->amount, 'settled_amount' => $settled, 'outstanding_amount' => $line->amount - $settled];
                }
            }
        }

        return ['rows' => $rows, 'totals' => collect($rows)->groupBy('liability_type')->map(fn ($group, string $type): array => ['liability_type' => $type, 'original_amount' => (int) $group->sum('original_amount'), 'settled_amount' => (int) $group->sum('settled_amount'), 'outstanding_amount' => (int) $group->sum('outstanding_amount')])->values()->all(), 'outstanding_total' => (int) collect($rows)->sum('outstanding_amount')];
    }

    /** @return array<int, array<string, mixed>> */
    public function employeeHistory(string $companyId, string $employeeId): array
    {
        return PayrollEntry::query()->where('company_id', $companyId)->where('employee_id', $employeeId)->with(['batch.period', 'lines', 'paymentAllocations'])->whereHas('batch', fn ($query) => $query->whereNotIn('status', ['DRAFT']))->orderByDesc('created_at')->get()->map(fn (PayrollEntry $entry): array => $this->entrySummary($entry) + ['batch' => $this->batchSummary($entry->batch)])->all();
    }

    /** @param array<string, mixed> $filters @return array<int, array<string, mixed>> */
    public function components(string $companyId, array $filters = []): array
    {
        $query = PayrollEntryLine::query()->where('payroll_entry_lines.company_id', $companyId)->whereHas('entry.batch', function ($query) use ($filters): void {
            $query->whereNotIn('status', ['DRAFT']);
            $this->dateFilters($query, $filters);
        });

        return $query->selectRaw('component_code, component_name, component_type, SUM(amount) AS total_amount, COUNT(DISTINCT payroll_entry_id) AS employee_count')->groupBy('component_code', 'component_name', 'component_type')->orderBy('component_type')->orderBy('component_code')->get()->map(fn (PayrollEntryLine $line): array => ['component_code' => $line->component_code, 'component_name' => $line->component_name, 'component_type' => $line->component_type, 'total_amount' => (int) $line->getAttribute('total_amount'), 'employee_count' => (int) $line->getAttribute('employee_count')])->all();
    }

    /** @return array<string, mixed> */
    public function payslip(string $companyId, PayrollEntry $entry): array
    {
        $entry = PayrollEntry::query()->where('company_id', $companyId)->with(['batch.period', 'batch.company', 'lines', 'paymentAllocations'])->findOrFail($entry->id);

        return ['company' => ['id' => $entry->batch->company_id, 'name' => $entry->batch->company->name], 'employee' => ['id' => $entry->employee_id, 'employee_code' => $entry->employee_code, 'full_name' => $entry->employee_name, 'department' => $entry->department, 'designation' => $entry->designation], 'payroll_period' => ['name' => $entry->batch->period->name, 'period_start' => $entry->batch->period->period_start->format('Y-m-d'), 'period_end' => $entry->batch->period->period_end->format('Y-m-d'), 'pay_date' => $entry->batch->period->pay_date->format('Y-m-d')], ...$this->entrySummary($entry), 'profile_snapshot' => $entry->profile_snapshot, 'statutory_rule_snapshot' => $entry->statutory_rule_snapshot, 'lines' => $entry->lines->map(fn ($line): array => ['id' => $line->id, 'component_code' => $line->component_code, 'component_name' => $line->component_name, 'component_type' => $line->component_type, 'amount' => $line->amount, 'is_taxable' => $line->is_taxable, 'gl_account_id' => $line->gl_account_id, 'liability_account_id' => $line->liability_account_id])->all()];
    }

    /** @return array<string, mixed> */
    public function reconciliation(string $companyId, PayrollBatch $batch): array
    {
        $batch = PayrollBatch::query()->where('company_id', $companyId)->with('journal.lines')->findOrFail($batch->id);
        $expectedDebit = $batch->employer_total_cost + $batch->reimbursements;
        $expectedCredit = $batch->net_pay + $batch->employee_deductions + $batch->employee_contributions + $batch->tax_amount + $batch->employer_contributions;
        $journalDebit = (int) ($batch->journal?->lines->sum('debit') ?? 0);
        $journalCredit = (int) ($batch->journal?->lines->sum('credit') ?? 0);
        $sourceJournalCount = $batch->journal_id === null ? 0 : JournalLine::query()->where('journal_id', $batch->journal_id)->count();

        $liabilityAccountIds = $batch->journal?->lines->where('credit', '>', 0)->pluck('account_id')->unique()->values()->all() ?? [];
        $entryIds = $batch->entries()->pluck('id')->all();
        $liabilityGlBalance = $liabilityAccountIds === [] ? 0 : (int) JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.company_id', $companyId)
            ->whereIn('journals.status', ['posted', 'reversed'])
            ->where(function ($query) use ($batch, $entryIds): void {
                $query->where(fn ($batchLines) => $batchLines->where('journal_lines.related_type', 'payroll_batch')->where('journal_lines.related_id', $batch->id));
                if ($entryIds !== []) {
                    $query->orWhere(fn ($entryLines) => $entryLines->where('journal_lines.related_type', 'payroll_entry')->whereIn('journal_lines.related_id', $entryIds));
                }
            })
            ->whereIn('journal_lines.account_id', $liabilityAccountIds)
            ->selectRaw('COALESCE(SUM(journal_lines.credit - journal_lines.debit), 0) AS balance')
            ->value('balance');
        $operationalOutstanding = (int) $this->liabilities($companyId, $batch->id)['outstanding_total'];
        $liabilityDifference = $liabilityGlBalance - $operationalOutstanding;

        return ['batch_id' => $batch->id, 'batch_number' => $batch->number, 'status' => $batch->status, 'journal_id' => $batch->journal_id, 'expected_debit' => $expectedDebit, 'expected_credit' => $expectedCredit, 'journal_debit' => $journalDebit, 'journal_credit' => $journalCredit, 'difference' => $expectedDebit - $journalDebit, 'operational_outstanding' => $operationalOutstanding, 'liability_gl_balance' => $liabilityGlBalance, 'liability_difference' => $liabilityDifference, 'liabilities_reconciled' => $liabilityDifference === 0, 'balanced' => $expectedDebit === $expectedCredit && $journalDebit === $journalCredit && $expectedDebit === $journalDebit && $sourceJournalCount > 0 && $liabilityDifference === 0];
    }

    /** @return array<string, mixed> */
    private function batchSummary(PayrollBatch $batch): array
    {
        return ['id' => $batch->id, 'number' => $batch->number, 'status' => $batch->status, 'accounting_date' => $batch->accounting_date->format('Y-m-d'), 'period' => $batch->relationLoaded('period') ? ['id' => $batch->period->id, 'name' => $batch->period->name, 'pay_date' => $batch->period->pay_date->format('Y-m-d')] : null, 'employee_count' => $batch->employee_count, 'gross_earnings' => $batch->gross_earnings, 'taxable_earnings' => $batch->taxable_earnings, 'employee_deductions' => $batch->employee_deductions, 'employee_contributions' => $batch->employee_contributions, 'tax_amount' => $batch->tax_amount, 'employer_contributions' => $batch->employer_contributions, 'reimbursements' => $batch->reimbursements, 'net_pay' => $batch->net_pay, 'employer_total_cost' => $batch->employer_total_cost, 'journal_id' => $batch->journal_id];
    }

    /** @return array<string, mixed> */
    private function entrySummary(PayrollEntry $entry): array
    {
        $paid = (int) ($entry->relationLoaded('paymentAllocations') ? $entry->paymentAllocations->sum('amount') : $entry->paymentAllocations()->sum('amount'));

        return ['id' => $entry->id, 'employee_id' => $entry->employee_id, 'employee_code' => $entry->employee_code, 'employee_name' => $entry->employee_name, 'department' => $entry->department, 'designation' => $entry->designation, 'base_salary' => $entry->base_salary, 'currency' => $entry->currency, 'gross_earnings' => $entry->gross_earnings, 'taxable_earnings' => $entry->taxable_earnings, 'employee_deductions' => $entry->employee_deductions, 'employee_contributions' => $entry->employee_contributions, 'tax_amount' => $entry->tax_amount, 'employer_contributions' => $entry->employer_contributions, 'reimbursements' => $entry->reimbursements, 'net_pay' => $entry->net_pay, 'employer_total_cost' => $entry->employer_total_cost, 'paid_amount' => $paid, 'outstanding_amount' => $entry->net_pay - $paid, 'payment_status' => $paid === 0 ? 'UNPAID' : ($paid >= $entry->net_pay ? 'PAID' : 'PARTIALLY_PAID')];
    }

    /** @param Builder<PayrollBatch> $query @param array<string, mixed> $filters */
    private function dateFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['from'])) {
            $query->whereDate('accounting_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('accounting_date', '<=', $filters['to']);
        }
    }
}
