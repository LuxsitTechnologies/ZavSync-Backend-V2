<?php

namespace App\Services\Payroll;

use App\Exceptions\PayrollException;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\PayrollBatch;
use App\Models\User;
use App\Services\Accounting\AccountMappingService;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollPostingService
{
    public function __construct(private readonly AccountMappingService $mappings, private readonly JournalPostingService $journals) {}

    public function post(string $companyId, User $user, PayrollBatch $batch, string $idempotencyKey): PayrollBatch
    {
        return DB::transaction(function () use ($companyId, $user, $batch, $idempotencyKey): PayrollBatch {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $batch = PayrollBatch::query()->where('company_id', $companyId)->with(['period', 'entries.lines'])->lockForUpdate()->findOrFail($batch->id);
            $hash = $this->postingHash($batch);
            if ($batch->posting_idempotency_key !== null) {
                if ($batch->posting_idempotency_key !== $idempotencyKey || ! hash_equals((string) $batch->posting_idempotency_hash, $hash)) {
                    throw new PayrollException('PAYROLL_IDEMPOTENCY_CONFLICT', 'The idempotency key conflicts with the existing payroll posting.', 409);
                }

                return $batch;
            }
            if ($batch->status !== 'APPROVED') {
                throw new PayrollException('PAYROLL_BATCH_NOT_APPROVED', 'Only approved payroll can be posted.');
            }
            if (! AccountingPeriod::query()->where('company_id', $companyId)->where('status', 'open')->whereDate('start_date', '<=', $batch->accounting_date)->whereDate('end_date', '>=', $batch->accounting_date)->exists()) {
                throw new PayrollException('PAYROLL_PERIOD_CLOSED', 'Payroll accounting date must belong to an open accounting period.');
            }
            $this->assertTotals($batch);
            try {
                $lines = $this->journalLines($companyId, $batch);
            } catch (ValidationException $exception) {
                if (array_key_exists('account_mappings', $exception->errors())) {
                    throw new PayrollException('PAYROLL_MAPPING_MISSING', 'Configure all required payroll account mappings before posting.');
                }

                throw $exception;
            }
            $journal = $this->journals->post($companyId, $user, [
                'posting_date' => $batch->accounting_date->format('Y-m-d'),
                'reference' => $batch->number,
                'reference_type' => 'payroll',
                'source_id' => $batch->id,
                'source' => 'payroll_posting',
                'description' => 'Payroll posting — '.$batch->period->name,
                'lines' => $lines,
            ], 'payroll-posting-'.$batch->id.'-'.$idempotencyKey);
            $batch->update(['status' => 'POSTED', 'journal_id' => $journal->id, 'posted_by' => $user->id, 'posted_at' => now(), 'posting_idempotency_key' => $idempotencyKey, 'posting_idempotency_hash' => $hash]);

            return $batch->fresh(['period', 'entries.lines', 'journal.lines.account']);
        }, 3);
    }

    public function reverse(string $companyId, User $user, PayrollBatch $batch, string $postingDate, string $reason, string $idempotencyKey): PayrollBatch
    {
        return DB::transaction(function () use ($companyId, $user, $batch, $postingDate, $reason, $idempotencyKey): PayrollBatch {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $batch = PayrollBatch::query()->where('company_id', $companyId)->with('journal')->lockForUpdate()->findOrFail($batch->id);
            if (! in_array($batch->status, ['POSTED', 'PARTIALLY_PAID', 'PAID'], true) || $batch->journal === null) {
                throw new PayrollException('PAYROLL_ALREADY_POSTED', 'Only posted payroll can be reversed.');
            }
            if ($batch->payments()->exists() || $batch->settlements()->exists()) {
                throw new PayrollException('PAYROLL_CORRECTION_BLOCKED', 'Reverse payroll payments and liability settlements before reversing payroll.');
            }
            $reversal = $this->journals->reverse($companyId, $user, $batch->journal, $postingDate, $reason, 'payroll-reversal-'.$batch->id.'-'.$idempotencyKey);
            $batch->update(['status' => 'CANCELLED', 'reversal_journal_id' => $reversal->id, 'correction_reason' => $reason, 'corrected_by' => $user->id, 'corrected_at' => now()]);

            return $batch->fresh(['period', 'entries.lines', 'journal']);
        }, 3);
    }

    /** @return array<int, array<string, mixed>> */
    public function preview(string $companyId, PayrollBatch $batch): array
    {
        $batch = PayrollBatch::query()->where('company_id', $companyId)->with('entries.lines')->findOrFail($batch->id);

        return $this->journalLines($companyId, $batch);
    }

    private function assertTotals(PayrollBatch $batch): void
    {
        $fields = ['gross_earnings', 'taxable_earnings', 'employee_deductions', 'employee_contributions', 'tax_amount', 'employer_contributions', 'reimbursements', 'net_pay', 'employer_total_cost'];
        foreach ($fields as $field) {
            if ((int) $batch->entries->sum($field) !== (int) $batch->{$field}) {
                throw new PayrollException('PAYROLL_CALCULATION_INVALID', 'Payroll totals no longer reconcile to employee entries.');
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function journalLines(string $companyId, PayrollBatch $batch): array
    {
        $salaryExpense = $this->mappings->require($companyId, 'salary_expense');
        $netPayable = $this->mappings->require($companyId, 'payroll_net_payable');
        $aggregated = [];
        $add = function (string $accountId, string $description, int $debit, int $credit, ?string $relatedId = null) use (&$aggregated): void {
            $key = $accountId.'|'.($relatedId ?? 'aggregate').'|'.($debit > 0 ? 'D' : 'C');
            if (! isset($aggregated[$key])) {
                $aggregated[$key] = ['account_id' => $accountId, 'description' => $description, 'debit' => 0, 'credit' => 0, 'related_type' => $relatedId === null ? 'payroll_batch' : 'payroll_entry', 'related_id' => $relatedId];
            }
            $aggregated[$key]['debit'] += $debit;
            $aggregated[$key]['credit'] += $credit;
        };

        foreach ($batch->entries as $entry) {
            foreach ($entry->lines as $line) {
                if (in_array($line->component_type, ['EARNINGS', 'REIMBURSEMENTS'], true)) {
                    $add($line->gl_account_id ?: $salaryExpense->id, $line->component_name.' — '.$entry->employee_code, $line->amount, 0, $entry->id);
                } elseif ($line->component_type === 'EMPLOYER_CONTRIBUTIONS') {
                    $expense = $line->gl_account_id ?: $this->mappings->require($companyId, 'payroll_employer_contribution_expense')->id;
                    $liability = $line->liability_account_id ?: $this->mappings->require($companyId, 'payroll_employer_contribution_payable')->id;
                    $add($expense, $line->component_name.' — '.$entry->employee_code, $line->amount, 0, $entry->id);
                    $add($liability, $line->component_name.' payable — '.$entry->employee_code, 0, $line->amount, $entry->id);
                } else {
                    $mappingKey = match ($line->component_type) {
                        'TAX' => 'payroll_tax_payable',
                        'EMPLOYEE_CONTRIBUTIONS' => 'payroll_employee_contribution_payable',
                        default => 'payroll_other_deduction_payable',
                    };
                    $liability = $line->liability_account_id ?: $this->mappings->require($companyId, $mappingKey)->id;
                    $add($liability, $line->component_name.' payable — '.$entry->employee_code, 0, $line->amount, $entry->id);
                }
            }
            $add($netPayable->id, 'Net pay payable — '.$entry->employee_code, 0, $entry->net_pay, $entry->id);
        }

        $lines = array_values(array_filter($aggregated, fn (array $line): bool => $line['debit'] > 0 || $line['credit'] > 0));
        if ((int) collect($lines)->sum('debit') !== (int) collect($lines)->sum('credit')) {
            throw new PayrollException('PAYROLL_CALCULATION_INVALID', 'The payroll posting journal is not balanced.');
        }

        return $lines;
    }

    private function postingHash(PayrollBatch $batch): string
    {
        return hash('sha256', json_encode(['batch_id' => $batch->id, 'accounting_date' => $batch->accounting_date->format('Y-m-d'), 'gross_earnings' => $batch->gross_earnings, 'net_pay' => $batch->net_pay, 'employer_total_cost' => $batch->employer_total_cost], JSON_THROW_ON_ERROR));
    }
}
