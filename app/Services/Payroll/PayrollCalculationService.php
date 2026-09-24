<?php

namespace App\Services\Payroll;

use App\Exceptions\PayrollException;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use Illuminate\Support\Facades\DB;

class PayrollCalculationService
{
    /** @var array<int, string> */
    private const EMPLOYEE_DEDUCTION_TYPES = ['DEDUCTIONS', 'EMPLOYEE_CONTRIBUTIONS', 'TAX'];

    public function calculate(string $companyId, PayrollBatch $batch): PayrollBatch
    {
        return DB::transaction(function () use ($companyId, $batch): PayrollBatch {
            $batch = PayrollBatch::query()->where('company_id', $companyId)->with(['period', 'entries.adjustments.component'])->lockForUpdate()->findOrFail($batch->id);
            if (! in_array($batch->status, ['DRAFT', 'CALCULATED'], true)) {
                throw new PayrollException('PAYROLL_POSTED_IMMUTABLE', 'Only draft or calculated payroll can be recalculated.');
            }

            foreach ($batch->entries as $entry) {
                $this->calculateEntry($companyId, $entry, $batch->period->period_end->format('Y-m-d'));
            }

            $entries = PayrollEntry::query()->where('payroll_batch_id', $batch->id)->get();
            $batch->update([
                'status' => 'CALCULATED',
                'employee_count' => $entries->count(),
                'gross_earnings' => (int) $entries->sum('gross_earnings'),
                'taxable_earnings' => (int) $entries->sum('taxable_earnings'),
                'employee_deductions' => (int) $entries->sum('employee_deductions'),
                'employee_contributions' => (int) $entries->sum('employee_contributions'),
                'tax_amount' => (int) $entries->sum('tax_amount'),
                'employer_contributions' => (int) $entries->sum('employer_contributions'),
                'reimbursements' => (int) $entries->sum('reimbursements'),
                'net_pay' => (int) $entries->sum('net_pay'),
                'employer_total_cost' => (int) $entries->sum('employer_total_cost'),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'approved_by' => null,
                'approved_at' => null,
            ]);

            return $batch->fresh(['period', 'entries.lines', 'entries.adjustments']);
        }, 3);
    }

    private function calculateEntry(string $companyId, PayrollEntry $entry, string $effectiveDate): void
    {
        $entry->lines()->delete();
        $lines = [[
            'company_id' => $companyId,
            'component_code' => 'BASIC',
            'component_name' => 'Basic Salary',
            'component_type' => 'EARNINGS',
            'amount' => $entry->base_salary,
            'is_taxable' => true,
            'calculation_snapshot' => ['method' => 'profile_base_salary'],
        ]];

        $assignments = collect($entry->profile_snapshot['components'] ?? [])
            ->filter(fn (array $assignment): bool => ($assignment['is_active'] ?? false)
                && ($assignment['component']['is_active'] ?? false)
                && (($assignment['effective_from'] ?? null) === null || $assignment['effective_from'] <= $effectiveDate)
                && (($assignment['effective_to'] ?? null) === null || $assignment['effective_to'] >= $effectiveDate)
                && (($assignment['component']['effective_from'] ?? null) === null || mb_substr((string) $assignment['component']['effective_from'], 0, 10) <= $effectiveDate)
                && (($assignment['component']['effective_to'] ?? null) === null || mb_substr((string) $assignment['component']['effective_to'], 0, 10) >= $effectiveDate));

        foreach ($assignments->filter(fn (array $assignment): bool => in_array($assignment['component']['type'], ['EARNINGS', 'REIMBURSEMENTS'], true)) as $assignment) {
            if ($assignment['component']['calculation_method'] === 'statutory') {
                continue;
            }
            $lines[] = $this->componentLine($companyId, $assignment['component'], $assignment['fixed_amount'], $assignment['rate_bps'], $entry->base_salary, null);
        }

        foreach ($entry->adjustments as $adjustment) {
            $lines[] = $this->componentLine($companyId, $adjustment->component->toArray(), $adjustment->amount, null, $entry->base_salary, $adjustment->id);
        }

        $gross = $this->sumType($lines, 'EARNINGS');
        $taxable = (int) collect($lines)->where('is_taxable', true)->where('component_type', 'EARNINGS')->sum('amount');

        foreach ($assignments->reject(fn (array $assignment): bool => in_array($assignment['component']['type'], ['EARNINGS', 'REIMBURSEMENTS'], true)) as $assignment) {
            if ($assignment['component']['calculation_method'] === 'statutory') {
                continue;
            }
            $base = match ($assignment['component']['calculation_base']) {
                'gross' => $gross,
                'taxable' => $taxable,
                default => $entry->base_salary,
            };
            $lines[] = $this->componentLine($companyId, $assignment['component'], $assignment['fixed_amount'], $assignment['rate_bps'], $base, null);
        }

        $ruleSnapshots = $entry->statutory_rule_snapshot ?? [];
        foreach ($ruleSnapshots as $rule) {
            $assignment = $assignments->firstWhere('payroll_component_id', $rule['payroll_component_id']);
            if ($assignment === null) {
                continue;
            }
            $component = $assignment['component'];
            $amount = $this->statutoryAmount($taxable, $rule);
            if ($amount === 0) {
                continue;
            }
            $lines[] = [
                'company_id' => $companyId,
                'payroll_component_id' => $component['id'],
                'statutory_rule_id' => $rule['id'],
                'component_code' => $component['code'],
                'component_name' => $component['name'],
                'component_type' => $component['type'],
                'amount' => $amount,
                'is_taxable' => $component['is_taxable'],
                'gl_account_id' => $component['gl_account_id'],
                'liability_account_id' => $component['liability_account_id'],
                'calculation_snapshot' => ['method' => 'statutory', 'rule_version' => $rule['version'], 'taxable_base' => $taxable, 'rate_bps' => $rule['rate_bps'], 'threshold_from' => $rule['threshold_from'], 'threshold_to' => $rule['threshold_to']],
            ];
        }

        $deductions = $this->sumType($lines, 'DEDUCTIONS');
        $employeeContributions = $this->sumType($lines, 'EMPLOYEE_CONTRIBUTIONS');
        $tax = $this->sumType($lines, 'TAX');
        $employerContributions = $this->sumType($lines, 'EMPLOYER_CONTRIBUTIONS');
        $reimbursements = $this->sumType($lines, 'REIMBURSEMENTS');
        $net = $gross + $reimbursements - $deductions - $employeeContributions - $tax;
        if ($net < 0) {
            throw new PayrollException('PAYROLL_CALCULATION_INVALID', "Employee {$entry->employee_code} has negative net pay.");
        }
        if (collect($lines)->contains(fn (array $line): bool => $line['amount'] > 9_007_199_254_740_991)) {
            throw new PayrollException('PAYROLL_CALCULATION_INVALID', 'A payroll amount exceeds the supported integer minor-unit range.');
        }

        $entry->lines()->createMany($lines);
        $entry->update([
            'statutory_rule_snapshot' => $ruleSnapshots,
            'gross_earnings' => $gross,
            'taxable_earnings' => $taxable,
            'employee_deductions' => $deductions,
            'employee_contributions' => $employeeContributions,
            'tax_amount' => $tax,
            'employer_contributions' => $employerContributions,
            'reimbursements' => $reimbursements,
            'net_pay' => $net,
            'employer_total_cost' => $gross + $employerContributions,
        ]);
    }

    /** @return array<string, mixed> */
    private function componentLine(string $companyId, array $component, ?int $fixedAmount, ?int $rateBps, int $base, ?string $adjustmentId): array
    {
        $amount = $fixedAmount ?? $component['fixed_amount'] ?? $this->roundBasisPoints($base, $rateBps ?? $component['rate_bps'] ?? 0);

        return [
            'company_id' => $companyId,
            'payroll_component_id' => $component['id'],
            'payroll_adjustment_id' => $adjustmentId,
            'component_code' => $component['code'],
            'component_name' => $component['name'],
            'component_type' => $component['type'],
            'amount' => $amount,
            'is_taxable' => $component['is_taxable'],
            'gl_account_id' => $component['gl_account_id'],
            'liability_account_id' => $component['liability_account_id'],
            'calculation_snapshot' => ['method' => $adjustmentId === null ? $component['calculation_method'] : 'manual_adjustment', 'base' => $base, 'fixed_amount' => $fixedAmount ?? $component['fixed_amount'], 'rate_bps' => $rateBps ?? $component['rate_bps']],
        ];
    }

    /** @param array<string, mixed> $rule */
    private function statutoryAmount(int $base, array $rule): int
    {
        if ($base <= $rule['threshold_from']) {
            return 0;
        }
        $upper = $rule['threshold_to'] === null ? $base : min($base, $rule['threshold_to']);
        $amount = $rule['fixed_amount'] + $this->roundBasisPoints(max(0, $upper - $rule['threshold_from']), $rule['rate_bps']);
        if ($rule['minimum_amount'] !== null) {
            $amount = max($amount, $rule['minimum_amount']);
        }
        if ($rule['maximum_amount'] !== null) {
            $amount = min($amount, $rule['maximum_amount']);
        }

        return $amount;
    }

    private function roundBasisPoints(int $amount, int $basisPoints): int
    {
        return intdiv($amount, 10_000) * $basisPoints + intdiv((($amount % 10_000) * $basisPoints) + 5_000, 10_000);
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function sumType(array $lines, string $type): int
    {
        return (int) collect($lines)->where('component_type', $type)->sum('amount');
    }
}
