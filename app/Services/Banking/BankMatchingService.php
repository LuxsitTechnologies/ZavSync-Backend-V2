<?php

namespace App\Services\Banking;

use App\Models\BankReconciliation;
use App\Models\BankReconciliationMatch;
use App\Models\BankTransaction;
use App\Models\CustomerPayment;
use App\Models\GatewaySettlement;
use App\Models\InternalTransfer;
use App\Models\Journal;
use App\Models\PayrollLiabilitySettlement;
use App\Models\PayrollPayment;
use App\Models\SupplierPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BankMatchingService
{
    /** @return Collection<int, array<string, mixed>> */
    public function suggestions(BankTransaction $transaction): Collection
    {
        $transaction->loadMissing('financialAccount');
        $from = $transaction->transaction_date->subDays(7)->toDateString();
        $to = $transaction->transaction_date->addDays(7)->toDateString();
        $candidates = collect();

        if ($transaction->direction->value === 'credit') {
            CustomerPayment::query()->where('company_id', $transaction->company_id)->where('bank_account_id', $transaction->financialAccount->gl_account_id)->where('amount', $transaction->amount)->whereBetween('payment_date', [$from, $to])->with('customer')->get()->each(function (CustomerPayment $payment) use ($candidates, $transaction): void {
                $candidates->push($this->candidate('customer_payment', $payment->id, $payment->number, $payment->payment_date->toDateString(), $payment->amount, $payment->customer->name, $transaction));
            });
            GatewaySettlement::query()->where('company_id', $transaction->company_id)->where('destination_financial_account_id', $transaction->financial_account_id)->where('net_amount', $transaction->amount)->whereBetween('settlement_date', [$from, $to])->get()->each(function (GatewaySettlement $settlement) use ($candidates, $transaction): void {
                $candidates->push($this->candidate('gateway_settlement', $settlement->id, $settlement->settlement_reference, $settlement->settlement_date->toDateString(), $settlement->net_amount, $settlement->provider, $transaction));
            });
        } else {
            SupplierPayment::query()->where('company_id', $transaction->company_id)->where('bank_account_id', $transaction->financialAccount->gl_account_id)->where('amount', $transaction->amount)->whereBetween('payment_date', [$from, $to])->with('supplier')->get()->each(function (SupplierPayment $payment) use ($candidates, $transaction): void {
                $candidates->push($this->candidate('supplier_payment', $payment->id, $payment->number, $payment->payment_date->toDateString(), $payment->amount, $payment->supplier->name, $transaction));
            });
            PayrollPayment::query()->where('company_id', $transaction->company_id)->where('financial_account_id', $transaction->financial_account_id)->where('amount', $transaction->amount)->whereBetween('payment_date', [$from, $to])->get()->each(function (PayrollPayment $payment) use ($candidates, $transaction): void {
                $candidates->push($this->candidate('payroll_payment', $payment->id, $payment->reference ?: $payment->number, $payment->payment_date->toDateString(), $payment->amount, 'Employee salaries', $transaction));
            });
            PayrollLiabilitySettlement::query()->where('company_id', $transaction->company_id)->where('financial_account_id', $transaction->financial_account_id)->where('amount', $transaction->amount)->whereBetween('payment_date', [$from, $to])->get()->each(function (PayrollLiabilitySettlement $settlement) use ($candidates, $transaction): void {
                $candidates->push($this->candidate('payroll_liability_settlement', $settlement->id, $settlement->reference ?: $settlement->number, $settlement->payment_date->toDateString(), $settlement->amount, $settlement->liability_type, $transaction));
            });
        }

        InternalTransfer::query()->where('company_id', $transaction->company_id)->where('amount', $transaction->amount)->whereBetween('transfer_date', [$from, $to])->where(function ($query) use ($transaction): void {
            if ($transaction->direction->value === 'credit') {
                $query->where('destination_financial_account_id', $transaction->financial_account_id);
            } else {
                $query->where('source_financial_account_id', $transaction->financial_account_id);
            }
        })->get()->each(function (InternalTransfer $transfer) use ($candidates, $transaction): void {
            $candidates->push($this->candidate('internal_transfer', $transfer->id, $transfer->number, $transfer->transfer_date->toDateString(), $transfer->amount, null, $transaction));
        });

        Journal::query()->where('company_id', $transaction->company_id)->whereIn('status', ['posted', 'reversed'])->whereBetween('posting_date', [$from, $to])->whereHas('lines', fn ($query) => $query->where('account_id', $transaction->financialAccount->gl_account_id)->where($transaction->direction->value === 'credit' ? 'debit' : 'credit', $transaction->amount))->get()->each(function (Journal $journal) use ($candidates, $transaction): void {
            $candidates->push($this->candidate('journal', $journal->id, $journal->reference ?: $journal->number, $journal->posting_date->toDateString(), $transaction->amount, $journal->description, $transaction));
        });

        $alreadyMatched = BankReconciliationMatch::query()->where('bank_reconciliation_matches.company_id', $transaction->company_id)->where('bank_reconciliation_matches.status', 'active')->join('bank_transactions', 'bank_transactions.id', '=', 'bank_reconciliation_matches.bank_transaction_id')->get(['matchable_type', 'matchable_id', 'bank_transactions.financial_account_id', 'bank_transactions.direction'])->map(fn (BankReconciliationMatch $match): string => implode(':', [$match->matchable_type, $match->matchable_id, $match->financial_account_id, $match->direction instanceof \BackedEnum ? $match->direction->value : $match->direction]))->all();

        return $candidates->reject(fn (array $candidate): bool => in_array(implode(':', [$candidate['type'], $candidate['id'], $transaction->financial_account_id, $transaction->direction->value]), $alreadyMatched, true))->unique(fn (array $candidate): string => $candidate['type'].':'.$candidate['id'])->sortByDesc('score')->values();
    }

    /** @param array<string, mixed> $data */
    public function match(string $companyId, User $user, BankTransaction $transaction, array $data, string $idempotencyKey): BankReconciliationMatch
    {
        return DB::transaction(function () use ($companyId, $user, $transaction, $data, $idempotencyKey): BankReconciliationMatch {
            $hash = hash('sha256', json_encode(['bank_transaction_id' => $transaction->id, ...$data], JSON_THROW_ON_ERROR));
            $existing = BankReconciliationMatch::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->idempotency_hash, $hash)) {
                    throw new ConflictHttpException('The idempotency key has already been used for a different bank match.');
                }

                return $existing->load('bankTransaction');
            }
            $transaction = BankTransaction::query()->where('company_id', $companyId)->with('financialAccount')->lockForUpdate()->findOrFail($transaction->id);
            $existing = BankReconciliationMatch::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->idempotency_hash, $hash)) {
                    throw new ConflictHttpException('The idempotency key has already been used for a different bank match.');
                }

                return $existing->load('bankTransaction');
            }
            $reconciliationId = $data['bank_reconciliation_id'] ?? null;
            if ($reconciliationId !== null) {
                $reconciliation = BankReconciliation::query()->where('company_id', $companyId)->where('financial_account_id', $transaction->financial_account_id)->findOrFail($reconciliationId);
                if ($reconciliation->status === 'completed') {
                    throw ValidationException::withMessages(['bank_reconciliation_id' => 'A completed reconciliation cannot be changed.']);
                }
            }
            $amount = (int) ($data['amount'] ?? $transaction->amount);
            $activeTotal = (int) $transaction->matches()->where('status', 'active')->sum('amount');
            if ($amount <= 0 || $activeTotal + $amount > $transaction->amount) {
                throw ValidationException::withMessages(['amount' => 'Matched amount must be positive and cannot exceed the unmatched bank amount.']);
            }
            $source = $this->resolveSource($companyId, $transaction, $data['matchable_type'], $data['matchable_id'], $amount);
            $sameSideAlreadyMatched = BankReconciliationMatch::query()->where('company_id', $companyId)->where('matchable_type', $data['matchable_type'])->where('matchable_id', $source->getKey())->where('status', 'active')->whereHas('bankTransaction', fn ($query) => $query->where('financial_account_id', $transaction->financial_account_id)->where('direction', $transaction->direction->value))->lockForUpdate()->first() !== null;
            if ($sameSideAlreadyMatched) {
                throw ValidationException::withMessages(['matchable_id' => 'This accounting transaction is already matched to bank evidence for the same account and direction.']);
            }
            $match = BankReconciliationMatch::query()->create([
                'company_id' => $companyId, 'bank_transaction_id' => $transaction->id, 'bank_reconciliation_id' => $reconciliationId,
                'matchable_type' => $data['matchable_type'], 'matchable_id' => $source->getKey(), 'amount' => $amount,
                'confidence' => $data['confidence'] ?? 'manual', 'reason' => $data['reason'] ?? 'Authorized manual match', 'status' => 'active',
                'idempotency_key' => $idempotencyKey, 'idempotency_hash' => $hash, 'matched_by' => $user->id, 'matched_at' => now(),
            ]);
            $transaction->update(['status' => $activeTotal + $amount === $transaction->amount ? 'matched' : 'partially_matched']);
            if ($data['matchable_type'] === 'gateway_settlement' && $activeTotal + $amount === $transaction->amount) {
                GatewaySettlement::query()->where('company_id', $companyId)->whereKey($source->getKey())->update(['status' => 'matched']);
            }

            return $match->load('bankTransaction');
        }, 3);
    }

    public function unmatch(string $companyId, User $user, BankReconciliationMatch $match, string $reason): BankReconciliationMatch
    {
        return DB::transaction(function () use ($companyId, $user, $match, $reason): BankReconciliationMatch {
            $match = BankReconciliationMatch::query()->where('company_id', $companyId)->with(['reconciliation', 'bankTransaction'])->lockForUpdate()->findOrFail($match->id);
            if ($match->status !== 'active') {
                throw ValidationException::withMessages(['match' => 'This match is no longer active.']);
            }
            if ($match->reconciliation?->status === 'completed') {
                throw ValidationException::withMessages(['match' => 'Reopen the completed reconciliation before unmatching.']);
            }
            $match->update(['status' => 'voided', 'unmatched_by' => $user->id, 'unmatched_at' => now(), 'unmatch_reason' => $reason]);
            $remaining = (int) $match->bankTransaction->matches()->where('status', 'active')->sum('amount');
            $match->bankTransaction->update(['status' => $remaining === 0 ? 'unmatched' : ($remaining === $match->bankTransaction->amount ? 'matched' : 'partially_matched')]);

            return $match->fresh(['bankTransaction']);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function candidate(string $type, string $id, string $reference, string $date, int $amount, ?string $party, BankTransaction $transaction): array
    {
        $haystack = mb_strtolower(implode(' ', [$transaction->bank_reference, $transaction->description, $transaction->counterparty_name]));
        $referenceMatch = str_contains($haystack, mb_strtolower($reference));
        $partyMatch = $party !== null && str_contains($haystack, mb_strtolower($party));
        $days = abs(CarbonImmutable::parse($date)->diffInDays($transaction->transaction_date));
        $confidence = $referenceMatch ? 'exact' : ($partyMatch ? 'high' : 'possible');
        $score = ($referenceMatch ? 100 : ($partyMatch ? 80 : 60)) - $days;

        return ['type' => $type, 'id' => $id, 'reference' => $reference, 'date' => $date, 'amount' => $amount, 'party' => $party, 'confidence' => $confidence, 'reason' => $referenceMatch ? 'Exact amount and reference within seven days' : ($partyMatch ? 'Exact amount and counterparty within seven days' : 'Exact amount within seven days'), 'score' => $score];
    }

    private function resolveSource(string $companyId, BankTransaction $transaction, string $type, string $id, int $amount): Model
    {
        $source = match ($type) {
            'customer_payment' => CustomerPayment::query()->where('company_id', $companyId)->where('bank_account_id', $transaction->financialAccount->gl_account_id)->findOrFail($id),
            'supplier_payment' => SupplierPayment::query()->where('company_id', $companyId)->where('bank_account_id', $transaction->financialAccount->gl_account_id)->findOrFail($id),
            'internal_transfer' => InternalTransfer::query()->where('company_id', $companyId)->findOrFail($id),
            'gateway_settlement' => GatewaySettlement::query()->where('company_id', $companyId)->where('destination_financial_account_id', $transaction->financial_account_id)->findOrFail($id),
            'payroll_payment' => PayrollPayment::query()->where('company_id', $companyId)->where('financial_account_id', $transaction->financial_account_id)->findOrFail($id),
            'payroll_liability_settlement' => PayrollLiabilitySettlement::query()->where('company_id', $companyId)->where('financial_account_id', $transaction->financial_account_id)->findOrFail($id),
            'journal' => Journal::query()->where('company_id', $companyId)->whereHas('lines', fn ($query) => $query->where('account_id', $transaction->financialAccount->gl_account_id)->where($transaction->direction->value === 'credit' ? 'debit' : 'credit', $amount))->findOrFail($id),
            default => throw ValidationException::withMessages(['matchable_type' => 'Unsupported accounting transaction type.']),
        };
        $sourceAmount = match ($type) {
            'gateway_settlement' => $source->net_amount,
            'journal' => $amount,
            default => $source->amount,
        };
        if ((int) $sourceAmount !== $amount) {
            throw ValidationException::withMessages(['amount' => 'The matched amount must equal the selected accounting transaction amount.']);
        }
        $compatible = match ($type) {
            'customer_payment', 'gateway_settlement' => $transaction->direction->value === 'credit',
            'supplier_payment', 'payroll_payment', 'payroll_liability_settlement' => $transaction->direction->value === 'debit',
            'internal_transfer' => $transaction->direction->value === 'credit' ? $source->destination_financial_account_id === $transaction->financial_account_id : $source->source_financial_account_id === $transaction->financial_account_id,
            default => true,
        };
        if (! $compatible) {
            throw ValidationException::withMessages(['matchable_id' => 'The selected accounting transaction has an incompatible bank direction or account.']);
        }

        return $source;
    }
}
