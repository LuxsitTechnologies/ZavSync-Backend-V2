<?php

namespace App\Services\Payroll;

use App\Exceptions\PayrollException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollAdjustment;
use App\Models\PayrollBatch;
use App\Models\PayrollComponent;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollStatutoryRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    /** @param array<string, mixed> $data */
    public function createPeriod(string $companyId, User $user, array $data): PayrollPeriod
    {
        return DB::transaction(function () use ($companyId, $user, $data): PayrollPeriod {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $overlap = PayrollPeriod::query()->where('company_id', $companyId)->where('frequency', $data['frequency'])->whereDate('period_start', '<=', $data['period_end'])->whereDate('period_end', '>=', $data['period_start'])->exists();
            if ($overlap) {
                throw new PayrollException('PAYROLL_PERIOD_INVALID', 'A payroll period with this frequency already overlaps the selected dates.');
            }

            return PayrollPeriod::query()->create([...$data, 'company_id' => $companyId, 'status' => 'open', 'created_by' => $user->id]);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createProfile(string $companyId, User $user, Employee $employee, array $data): EmployeePayrollProfile
    {
        return DB::transaction(function () use ($companyId, $user, $employee, $data): EmployeePayrollProfile {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $employee = Employee::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($employee->id);
            $effectiveFrom = CarbonImmutable::parse($data['effective_from']);
            $previous = EmployeePayrollProfile::query()->where('company_id', $companyId)->where('employee_id', $employee->id)->whereDate('effective_from', '<', $effectiveFrom)->orderByDesc('effective_from')->lockForUpdate()->first();
            if ($previous !== null && ($previous->effective_to === null || $previous->effective_to->gte($effectiveFrom))) {
                $previous->update(['effective_to' => $effectiveFrom->subDay()->format('Y-m-d')]);
            }

            $componentData = $data['components'] ?? [];
            unset($data['components']);
            $profile = EmployeePayrollProfile::query()->create([...$data, 'company_id' => $companyId, 'employee_id' => $employee->id, 'created_by' => $user->id]);
            foreach ($componentData as $componentInput) {
                $component = PayrollComponent::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($componentInput['payroll_component_id']);
                $profile->components()->create([
                    'company_id' => $companyId,
                    'payroll_component_id' => $component->id,
                    'fixed_amount' => $componentInput['fixed_amount'] ?? null,
                    'rate_bps' => $componentInput['rate_bps'] ?? null,
                    'effective_from' => $componentInput['effective_from'] ?? null,
                    'effective_to' => $componentInput['effective_to'] ?? null,
                    'is_active' => $componentInput['is_active'] ?? true,
                ]);
            }

            return $profile->load(['employee', 'components.component', 'paymentFinancialAccount']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createBatch(string $companyId, User $user, array $data): PayrollBatch
    {
        return DB::transaction(function () use ($companyId, $user, $data): PayrollBatch {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $period = PayrollPeriod::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($data['payroll_period_id']);
            if ($period->status !== 'open') {
                throw new PayrollException('PAYROLL_PERIOD_INVALID', 'Only an open payroll period can be processed.');
            }
            if (PayrollBatch::query()->where('company_id', $companyId)->where('payroll_period_id', $period->id)->where('status', '!=', 'CANCELLED')->exists()) {
                throw new PayrollException('PAYROLL_BATCH_ALREADY_EXISTS', 'A payroll batch already exists for this payroll period.', 409);
            }
            $correctionOf = null;
            if (isset($data['correction_of_batch_id'])) {
                $correctionOf = PayrollBatch::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($data['correction_of_batch_id']);
                if ($correctionOf->payroll_period_id !== $period->id || $correctionOf->status !== 'CANCELLED' || $correctionOf->reversal_journal_id === null || PayrollBatch::query()->where('company_id', $companyId)->where('correction_of_batch_id', $correctionOf->id)->exists()) {
                    throw new PayrollException('PAYROLL_CORRECTION_INVALID', 'A replacement batch requires an unreplaced, reversed payroll from the same period.');
                }
            } elseif (PayrollBatch::query()->where('company_id', $companyId)->where('payroll_period_id', $period->id)->where('status', 'CANCELLED')->exists()) {
                throw new PayrollException('PAYROLL_CORRECTION_INVALID', 'Select the reversed payroll batch that this replacement corrects.');
            }
            $accountingDate = $data['accounting_date'] ?? $period->pay_date->format('Y-m-d');
            $sequence = (int) PayrollBatch::query()->where('company_id', $companyId)->max('sequence') + 1;
            $batch = PayrollBatch::query()->create([
                'company_id' => $companyId,
                'payroll_period_id' => $period->id,
                'sequence' => $sequence,
                'number' => sprintf('PAY-%s-%04d', $period->period_end->format('Y'), $sequence),
                'status' => 'DRAFT',
                'accounting_date' => $accountingDate,
                'correction_of_batch_id' => $correctionOf?->id,
                'created_by' => $user->id,
            ]);

            $profiles = EmployeePayrollProfile::query()
                ->where('company_id', $companyId)
                ->where('pay_frequency', $period->frequency)
                ->where('payroll_status', 'active')
                ->whereDate('effective_from', '<=', $period->period_end)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $period->period_start))
                ->whereHas('employee', fn ($query) => $query->whereIn('status', ['active', 'probation', 'on_leave', 'notice_period'])->whereDate('joining_date', '<=', $period->period_end)->where(fn ($employeeQuery) => $employeeQuery->whereNull('leaving_date')->orWhereDate('leaving_date', '>=', $period->period_start)))
                ->with(['employee', 'components.component'])
                ->orderByDesc('effective_from')
                ->get()
                ->unique('employee_id');

            $rules = PayrollStatutoryRule::query()
                ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId))
                ->where('is_active', true)
                ->whereDate('effective_from', '<=', $period->period_end)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $period->period_end))
                ->orderBy('threshold_from')
                ->get()
                ->map(fn (PayrollStatutoryRule $rule): array => $rule->only(['id', 'company_id', 'payroll_component_id', 'jurisdiction', 'rule_type', 'version', 'effective_from', 'effective_to', 'threshold_from', 'threshold_to', 'rate_bps', 'fixed_amount', 'minimum_amount', 'maximum_amount']))
                ->values()
                ->all();

            foreach ($profiles as $profile) {
                $componentSnapshot = $profile->components->map(function ($assignment): array {
                    return [
                        'payroll_component_id' => $assignment->payroll_component_id,
                        'fixed_amount' => $assignment->fixed_amount,
                        'rate_bps' => $assignment->rate_bps,
                        'effective_from' => $assignment->effective_from?->format('Y-m-d'),
                        'effective_to' => $assignment->effective_to?->format('Y-m-d'),
                        'is_active' => $assignment->is_active,
                        'component' => $assignment->component?->only(['id', 'code', 'name', 'type', 'calculation_method', 'fixed_amount', 'rate_bps', 'calculation_base', 'is_taxable', 'is_active', 'effective_from', 'effective_to', 'gl_account_id', 'liability_account_id']),
                    ];
                })->all();
                PayrollEntry::query()->create([
                    'company_id' => $companyId,
                    'payroll_batch_id' => $batch->id,
                    'employee_id' => $profile->employee_id,
                    'employee_payroll_profile_id' => $profile->id,
                    'employee_code' => $profile->employee->employee_code,
                    'employee_name' => $profile->employee->full_name,
                    'department' => $profile->employee->department,
                    'designation' => $profile->employee->designation,
                    'base_salary' => $profile->base_salary,
                    'currency' => $profile->currency,
                    'profile_snapshot' => ['profile_id' => $profile->id, 'pay_frequency' => $profile->pay_frequency, 'base_salary' => $profile->base_salary, 'currency' => $profile->currency, 'effective_from' => $profile->effective_from->format('Y-m-d'), 'effective_to' => $profile->effective_to?->format('Y-m-d'), 'tax_identifier' => $profile->tax_identifier, 'statutory_registration' => $profile->statutory_registration, 'payment_financial_account_id' => $profile->payment_financial_account_id, 'employee_bank_reference' => $profile->employee_bank_reference, 'components' => $componentSnapshot],
                    'statutory_rule_snapshot' => $rules,
                ]);
            }
            if ($profiles->isEmpty()) {
                throw new PayrollException('PAYROLL_EMPLOYEES_NOT_ELIGIBLE', 'No eligible employee payroll profiles were found for this period.');
            }
            $batch->update(['employee_count' => $profiles->count()]);

            return $batch->fresh(['period', 'entries']);
        }, 3);
    }

    public function review(string $companyId, User $user, PayrollBatch $batch): PayrollBatch
    {
        return $this->transition($companyId, $batch, 'CALCULATED', 'REVIEWED', ['reviewed_by' => $user->id, 'reviewed_at' => now()]);
    }

    public function approve(string $companyId, User $user, PayrollBatch $batch): PayrollBatch
    {
        return $this->transition($companyId, $batch, 'REVIEWED', 'APPROVED', ['approved_by' => $user->id, 'approved_at' => now()]);
    }

    public function addAdjustment(string $companyId, User $user, PayrollEntry $entry, PayrollComponent $component, int $amount, string $reason): PayrollAdjustment
    {
        return DB::transaction(function () use ($companyId, $user, $entry, $component, $amount, $reason): PayrollAdjustment {
            $entry = PayrollEntry::query()->where('company_id', $companyId)->with('batch')->lockForUpdate()->findOrFail($entry->id);
            $component = PayrollComponent::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($component->id);
            if (! in_array($entry->batch->status, ['DRAFT', 'CALCULATED'], true)) {
                throw new PayrollException('PAYROLL_POSTED_IMMUTABLE', 'Adjustments cannot be changed after payroll review, approval, or posting.');
            }
            $entry->batch->update(['status' => 'DRAFT']);

            return PayrollAdjustment::query()->create(['company_id' => $companyId, 'payroll_entry_id' => $entry->id, 'payroll_component_id' => $component->id, 'amount' => $amount, 'reason' => $reason, 'created_by' => $user->id]);
        }, 3);
    }

    /** @param array<string, mixed> $changes */
    private function transition(string $companyId, PayrollBatch $batch, string $from, string $to, array $changes): PayrollBatch
    {
        return DB::transaction(function () use ($companyId, $batch, $from, $to, $changes): PayrollBatch {
            $batch = PayrollBatch::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== $from) {
                throw new PayrollException('PAYROLL_STATUS_INVALID', "Payroll must be {$from} before it can move to {$to}.");
            }
            $batch->update(['status' => $to, ...$changes]);

            return $batch->fresh(['period', 'entries.lines']);
        }, 3);
    }
}
