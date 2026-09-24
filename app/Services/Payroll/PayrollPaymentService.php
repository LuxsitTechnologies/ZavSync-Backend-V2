<?php

namespace App\Services\Payroll;

use App\Exceptions\PayrollException;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\PayrollBatch;
use App\Models\PayrollEntryLine;
use App\Models\PayrollLiabilitySettlement;
use App\Models\PayrollPayment;
use App\Models\User;
use App\Services\Accounting\AccountMappingService;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayrollPaymentService
{
    public function __construct(private readonly AccountMappingService $mappings, private readonly JournalPostingService $journals) {}

    /** @param array<string, mixed> $data */
    public function pay(string $companyId, User $user, PayrollBatch $batch, array $data, string $idempotencyKey): PayrollPayment
    {
        return DB::transaction(function () use ($companyId, $user, $batch, $data, $idempotencyKey): PayrollPayment {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode(['batch_id' => $batch->id, ...$data], JSON_THROW_ON_ERROR));
            $existing = PayrollPayment::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $this->assertHash($existing->idempotency_hash, $hash);

                return $existing->load(['allocations.entry', 'financialAccount', 'journal']);
            }
            $batch = PayrollBatch::query()->where('company_id', $companyId)->with('entries.paymentAllocations')->lockForUpdate()->findOrFail($batch->id);
            if (! in_array($batch->status, ['POSTED', 'PARTIALLY_PAID'], true)) {
                throw new PayrollException('PAYROLL_BATCH_NOT_POSTED', 'Salary payments require posted payroll.');
            }
            $financialAccount = $this->financialAccount($companyId, $data['financial_account_id'], $batch->entries->first()?->currency ?? 'PKR');
            $allocations = $this->paymentAllocations($batch, $data['allocations'] ?? [], (int) $data['amount']);
            $paymentId = (string) Str::uuid();
            $netPayable = $this->mappings->require($companyId, 'payroll_net_payable');
            $journal = $this->journals->post($companyId, $user, [
                'posting_date' => $data['payment_date'],
                'reference' => $data['reference'] ?? $batch->number,
                'reference_type' => 'payroll',
                'source_id' => $paymentId,
                'source' => 'payroll_payment',
                'description' => 'Salary payment — '.$batch->number,
                'lines' => [
                    ['account_id' => $netPayable->id, 'description' => 'Settle employee net pay liability', 'debit' => (int) $data['amount'], 'credit' => 0, 'related_type' => 'payroll_batch', 'related_id' => $batch->id],
                    ['account_id' => $financialAccount->gl_account_id, 'description' => 'Salary payment from '.$financialAccount->name, 'debit' => 0, 'credit' => (int) $data['amount'], 'related_type' => 'payroll_batch', 'related_id' => $batch->id],
                ],
            ], 'payroll-payment-'.$idempotencyKey);
            $sequence = (int) PayrollPayment::query()->where('company_id', $companyId)->max('sequence') + 1;
            $payment = new PayrollPayment(['company_id' => $companyId, 'sequence' => $sequence, 'number' => sprintf('PP-%s-%04d', mb_substr($data['payment_date'], 0, 4), $sequence), 'payroll_batch_id' => $batch->id, 'financial_account_id' => $financialAccount->id, 'payment_date' => $data['payment_date'], 'amount' => $data['amount'], 'currency' => $financialAccount->currency, 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'journal_id' => $journal->id, 'idempotency_key' => $idempotencyKey, 'idempotency_hash' => $hash, 'created_by' => $user->id]);
            $payment->id = $paymentId;
            $payment->save();
            $payment->allocations()->createMany(array_map(fn (array $allocation): array => ['company_id' => $companyId, ...$allocation], $allocations));
            $this->refreshBatchPaymentStatus($batch);

            return $payment->load(['allocations.entry', 'financialAccount', 'journal']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function settle(string $companyId, User $user, array $data, string $idempotencyKey): PayrollLiabilitySettlement
    {
        return DB::transaction(function () use ($companyId, $user, $data, $idempotencyKey): PayrollLiabilitySettlement {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = PayrollLiabilitySettlement::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $this->assertHash($existing->idempotency_hash, $hash);

                return $existing->load(['allocations.entryLine.entry', 'financialAccount', 'journal']);
            }
            $batch = PayrollBatch::query()->where('company_id', $companyId)->whereIn('status', ['POSTED', 'PARTIALLY_PAID', 'PAID'])->lockForUpdate()->findOrFail($data['payroll_batch_id']);
            $currency = (string) $batch->entries()->value('currency');
            $financialAccount = $this->financialAccount($companyId, $data['financial_account_id'], $currency);
            $allocations = $this->liabilityAllocations($companyId, $batch, $data['liability_type'], (int) $data['amount']);
            $settlementId = (string) Str::uuid();
            $debitLines = collect($allocations)->groupBy('account_id')->map(function ($rows, string $accountId): array {
                return ['account_id' => $accountId, 'description' => 'Settle payroll liability', 'debit' => (int) $rows->sum('amount'), 'credit' => 0, 'related_type' => 'payroll_batch', 'related_id' => $rows->first()['batch_id']];
            })->values()->all();
            $journal = $this->journals->post($companyId, $user, [
                'posting_date' => $data['payment_date'],
                'reference' => $data['reference'] ?? $batch->number,
                'reference_type' => 'payroll',
                'source_id' => $settlementId,
                'source' => 'payroll_liability_settlement',
                'description' => 'Payroll liability settlement — '.$data['liability_type'],
                'lines' => [...$debitLines, ['account_id' => $financialAccount->gl_account_id, 'description' => 'Payroll liability payment from '.$financialAccount->name, 'debit' => 0, 'credit' => (int) $data['amount'], 'related_type' => 'payroll_batch', 'related_id' => $batch->id]],
            ], 'payroll-liability-'.$idempotencyKey);
            $sequence = (int) PayrollLiabilitySettlement::query()->where('company_id', $companyId)->max('sequence') + 1;
            $settlement = new PayrollLiabilitySettlement(['company_id' => $companyId, 'sequence' => $sequence, 'number' => sprintf('PLS-%s-%04d', mb_substr($data['payment_date'], 0, 4), $sequence), 'payroll_batch_id' => $batch->id, 'liability_type' => $data['liability_type'], 'financial_account_id' => $financialAccount->id, 'payment_date' => $data['payment_date'], 'amount' => $data['amount'], 'currency' => $currency, 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'journal_id' => $journal->id, 'idempotency_key' => $idempotencyKey, 'idempotency_hash' => $hash, 'created_by' => $user->id]);
            $settlement->id = $settlementId;
            $settlement->save();
            $settlement->allocations()->createMany(array_map(fn (array $allocation): array => ['company_id' => $companyId, 'payroll_entry_line_id' => $allocation['payroll_entry_line_id'], 'amount' => $allocation['amount']], $allocations));

            return $settlement->load(['allocations.entryLine.entry', 'financialAccount', 'journal']);
        }, 3);
    }

    private function financialAccount(string $companyId, string $id, string $currency): FinancialAccount
    {
        $account = FinancialAccount::query()->where('company_id', $companyId)->where('is_active', true)->with('glAccount')->findOrFail($id);
        if ($account->currency !== $currency || ! $account->glAccount?->is_active) {
            throw new PayrollException('PAYROLL_FINANCIAL_ACCOUNT_INVALID', 'The financial account must be active, company-owned, and use the payroll currency.');
        }

        return $account;
    }

    /** @param array<int, array<string, mixed>> $requested @return array<int, array{payroll_entry_id:string,amount:int}> */
    private function paymentAllocations(PayrollBatch $batch, array $requested, int $amount): array
    {
        $remaining = $amount;
        $allocations = [];
        $requestedByEntry = collect($requested)->keyBy('payroll_entry_id');
        foreach ($batch->entries as $entry) {
            $outstanding = $entry->net_pay - (int) $entry->paymentAllocations->sum('amount');
            $allocation = $requested === [] ? min($remaining, $outstanding) : (int) ($requestedByEntry->get($entry->id)['amount'] ?? 0);
            if ($allocation < 0 || $allocation > $outstanding) {
                throw new PayrollException('PAYROLL_PAYMENT_EXCEEDS_OUTSTANDING', 'A salary payment allocation exceeds the employee outstanding balance.');
            }
            if ($allocation > 0) {
                $allocations[] = ['payroll_entry_id' => $entry->id, 'amount' => $allocation];
                $remaining -= $allocation;
            }
        }
        if ($amount <= 0 || $remaining !== 0 || array_sum(array_column($allocations, 'amount')) !== $amount) {
            throw new PayrollException('PAYROLL_PAYMENT_EXCEEDS_OUTSTANDING', 'Salary payment must be positive and cannot exceed outstanding net pay.');
        }

        return $allocations;
    }

    /** @return array<int, array{payroll_entry_line_id:string,amount:int,account_id:string,batch_id:string}> */
    private function liabilityAllocations(string $companyId, PayrollBatch $batch, string $type, int $amount): array
    {
        $componentTypes = match ($type) {
            'TAX' => ['TAX'],
            'EMPLOYEE_CONTRIBUTION' => ['EMPLOYEE_CONTRIBUTIONS'],
            'EMPLOYER_CONTRIBUTION' => ['EMPLOYER_CONTRIBUTIONS'],
            'OTHER_DEDUCTION' => ['DEDUCTIONS'],
            default => throw new PayrollException('PAYROLL_LIABILITY_INVALID', 'Unsupported payroll liability type.'),
        };
        $lines = PayrollEntryLine::query()->where('company_id', $companyId)->whereIn('component_type', $componentTypes)->whereHas('entry', fn ($query) => $query->where('payroll_batch_id', $batch->id))->with('settlementAllocations')->lockForUpdate()->get();
        $remaining = $amount;
        $allocations = [];
        foreach ($lines as $line) {
            $outstanding = $line->amount - (int) $line->settlementAllocations->sum('amount');
            $allocated = min($remaining, $outstanding);
            if ($allocated <= 0) {
                continue;
            }
            $mappingKey = match ($line->component_type) {
                'TAX' => 'payroll_tax_payable',
                'EMPLOYEE_CONTRIBUTIONS' => 'payroll_employee_contribution_payable',
                'EMPLOYER_CONTRIBUTIONS' => 'payroll_employer_contribution_payable',
                default => 'payroll_other_deduction_payable',
            };
            $allocations[] = ['payroll_entry_line_id' => $line->id, 'amount' => $allocated, 'account_id' => $line->liability_account_id ?: $this->mappings->require($companyId, $mappingKey)->id, 'batch_id' => $batch->id];
            $remaining -= $allocated;
        }
        if ($amount <= 0 || $remaining !== 0) {
            throw new PayrollException('PAYROLL_LIABILITY_OVERSETTLEMENT', 'Settlement amount exceeds the outstanding payroll liability.');
        }

        return $allocations;
    }

    private function refreshBatchPaymentStatus(PayrollBatch $batch): void
    {
        $paid = (int) $batch->entries()->withSum('paymentAllocations', 'amount')->get()->sum('payment_allocations_sum_amount');
        $batch->update(['status' => $paid >= $batch->net_pay ? 'PAID' : 'PARTIALLY_PAID']);
    }

    private function assertHash(string $existing, string $incoming): void
    {
        if (! hash_equals($existing, $incoming)) {
            throw new PayrollException('PAYROLL_IDEMPOTENCY_CONFLICT', 'The idempotency key has already been used for a different payroll request.', 409);
        }
    }
}
