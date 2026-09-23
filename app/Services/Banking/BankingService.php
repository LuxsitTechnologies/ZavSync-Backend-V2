<?php

namespace App\Services\Banking;

use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\CustomerPayment;
use App\Models\FinancialAccount;
use App\Models\GatewaySettlement;
use App\Models\InternalTransfer;
use App\Models\Invoice;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\CustomerPaymentService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\SupplierPaymentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BankingService
{
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly CustomerPaymentService $customerPaymentService,
        private readonly SupplierPaymentService $supplierPaymentService,
        private readonly BankMatchingService $matchingService,
        private readonly BankingReportingService $reportingService,
    ) {}

    /** @param array<string, mixed> $data */
    public function classify(string $companyId, User $user, BankTransaction $transaction, array $data, string $idempotencyKey): BankTransaction
    {
        return DB::transaction(function () use ($companyId, $user, $transaction, $data, $idempotencyKey): BankTransaction {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $transaction = BankTransaction::query()->where('company_id', $companyId)->with('financialAccount')->lockForUpdate()->findOrFail($transaction->id);
            $hash = hash('sha256', json_encode(['bank_transaction_id' => $transaction->id, ...$data], JSON_THROW_ON_ERROR));
            if ($transaction->origin_idempotency_key !== null) {
                if ($transaction->origin_idempotency_key !== $idempotencyKey || ! hash_equals((string) $transaction->origin_idempotency_hash, $hash)) {
                    throw new ConflictHttpException('This bank transaction has already been classified with different request data.');
                }

                return $transaction->load('classificationJournal.lines.account');
            }
            if (! in_array($transaction->status->value, ['unmatched', 'suggested'], true)) {
                throw ValidationException::withMessages(['bank_transaction' => 'Only an unmatched bank transaction can be classified.']);
            }
            $counterpart = Account::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($data['counterpart_account_id']);
            if ($counterpart->id === $transaction->financialAccount->gl_account_id) {
                throw ValidationException::withMessages(['counterpart_account_id' => 'The counterpart cannot be the same bank GL account.']);
            }
            $bankLine = ['account_id' => $transaction->financialAccount->gl_account_id, 'description' => $data['description'], 'debit' => $transaction->direction->value === 'credit' ? $transaction->amount : 0, 'credit' => $transaction->direction->value === 'debit' ? $transaction->amount : 0, 'related_type' => 'bank_transaction', 'related_id' => $transaction->id];
            $counterpartLine = ['account_id' => $counterpart->id, 'description' => $data['description'], 'debit' => $bankLine['credit'], 'credit' => $bankLine['debit'], 'related_type' => 'bank_transaction', 'related_id' => $transaction->id];
            $journal = $this->journalPostingService->post($companyId, $user, ['posting_date' => $data['posting_date'] ?? $transaction->transaction_date->toDateString(), 'reference' => $transaction->bank_reference, 'reference_type' => 'bank_classification', 'source_id' => $transaction->id, 'source' => 'banking', 'description' => $data['description'], 'lines' => [$bankLine, $counterpartLine]], 'bank-classification:'.hash('sha256', $idempotencyKey));
            $transaction->update(['status' => 'classified', 'classification_journal_id' => $journal->id, 'origin_idempotency_key' => $idempotencyKey, 'origin_idempotency_hash' => $hash]);

            return $transaction->fresh(['classificationJournal.lines.account']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function internalTransfer(string $companyId, User $user, array $data, string $idempotencyKey): InternalTransfer
    {
        return DB::transaction(function () use ($companyId, $user, $data, $idempotencyKey): InternalTransfer {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = InternalTransfer::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $this->assertSameHash($existing, $hash, 'internal transfer');

                return $existing->load(['sourceAccount', 'destinationAccount', 'journal.lines.account']);
            }
            $source = FinancialAccount::query()->where('company_id', $companyId)->where('is_active', true)->lockForUpdate()->findOrFail($data['source_financial_account_id']);
            $destination = FinancialAccount::query()->where('company_id', $companyId)->where('is_active', true)->lockForUpdate()->findOrFail($data['destination_financial_account_id']);
            if ($source->id === $destination->id) {
                throw ValidationException::withMessages(['destination_financial_account_id' => 'Destination account must differ from source account.']);
            }
            if ($source->currency !== $destination->currency) {
                throw ValidationException::withMessages(['currency' => 'Cross-currency transfers require the deferred FX accounting engine.']);
            }
            $sequence = (int) InternalTransfer::query()->where('company_id', $companyId)->max('sequence') + 1;
            $number = sprintf('TRF-%s-%04d', date('Y', strtotime($data['transfer_date'])), $sequence);
            $transferId = (string) Str::uuid();
            $journal = $this->journalPostingService->post($companyId, $user, ['posting_date' => $data['transfer_date'], 'reference' => $data['reference'] ?? $number, 'reference_type' => 'internal_transfer', 'source_id' => $transferId, 'source' => 'banking', 'description' => 'Internal transfer — '.$number, 'lines' => [
                ['account_id' => $destination->gl_account_id, 'description' => 'Transfer received', 'debit' => (int) $data['amount'], 'credit' => 0, 'related_type' => 'internal_transfer', 'related_id' => $transferId],
                ['account_id' => $source->gl_account_id, 'description' => 'Transfer sent', 'debit' => 0, 'credit' => (int) $data['amount'], 'related_type' => 'internal_transfer', 'related_id' => $transferId],
            ]], 'internal-transfer:'.hash('sha256', $idempotencyKey));
            $transfer = new InternalTransfer(['company_id' => $companyId, 'sequence' => $sequence, 'number' => $number, 'source_financial_account_id' => $source->id, 'destination_financial_account_id' => $destination->id, 'transfer_date' => $data['transfer_date'], 'amount' => $data['amount'], 'currency' => $source->currency, 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'journal_id' => $journal->id, 'idempotency_key' => $idempotencyKey, 'idempotency_hash' => $hash, 'created_by' => $user->id]);
            $transfer->id = $transferId;
            $transfer->save();

            return $transfer->load(['sourceAccount', 'destinationAccount', 'journal.lines.account']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function cashTransaction(string $companyId, User $user, array $data, string $idempotencyKey): BankTransaction
    {
        return DB::transaction(function () use ($companyId, $user, $data, $idempotencyKey): BankTransaction {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $fingerprint = hash('sha256', $companyId.'|cash|'.$idempotencyKey);
            $existing = BankTransaction::query()->where('company_id', $companyId)->where('fingerprint', $fingerprint)->first();
            if ($existing !== null) {
                $classificationData = ['counterpart_account_id' => $data['counterpart_account_id'], 'posting_date' => $data['transaction_date'], 'description' => $data['description']];
                $expectedHash = hash('sha256', json_encode(['bank_transaction_id' => $existing->id, ...$classificationData], JSON_THROW_ON_ERROR));
                if (! hash_equals((string) $existing->origin_idempotency_hash, $expectedHash)) {
                    throw new ConflictHttpException('The idempotency key has already been used for a different cash transaction.');
                }

                return $existing->load('classificationJournal.lines.account');
            }
            $account = FinancialAccount::query()->where('company_id', $companyId)->where('type', 'cash')->where('is_active', true)->findOrFail($data['financial_account_id']);
            $transaction = BankTransaction::query()->create(['company_id' => $companyId, 'financial_account_id' => $account->id, 'evidence_type' => 'cash_manual', 'transaction_date' => $data['transaction_date'], 'description' => $data['description'], 'bank_reference' => $data['reference'] ?? null, 'direction' => $data['direction'], 'amount' => $data['amount'], 'currency' => $account->currency, 'fingerprint' => $fingerprint, 'status' => 'unmatched', 'created_by' => $user->id]);

            return $this->classify($companyId, $user, $transaction, ['counterpart_account_id' => $data['counterpart_account_id'], 'posting_date' => $data['transaction_date'], 'description' => $data['description']], $idempotencyKey);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function gatewaySettlement(string $companyId, User $user, array $data, string $idempotencyKey): GatewaySettlement
    {
        return DB::transaction(function () use ($companyId, $user, $data, $idempotencyKey): GatewaySettlement {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = GatewaySettlement::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $this->assertSameHash($existing, $hash, 'gateway settlement');

                return $existing->load(['destinationAccount', 'allocations', 'journal.lines.account']);
            }
            if (GatewaySettlement::query()->where('company_id', $companyId)->where('provider', $data['provider'])->where('settlement_reference', $data['settlement_reference'])->exists()) {
                throw new ConflictHttpException('This gateway settlement reference has already been recorded for the provider.');
            }
            $adjustment = (int) ($data['adjustment_amount'] ?? 0);
            if ((int) $data['gross_amount'] - (int) $data['fee_amount'] + $adjustment !== (int) $data['net_amount']) {
                throw ValidationException::withMessages(['net_amount' => 'Net amount must equal gross amount minus fees plus adjustments.']);
            }
            $destination = FinancialAccount::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($data['destination_financial_account_id']);
            if ($destination->currency !== $data['currency']) {
                throw ValidationException::withMessages(['currency' => 'Settlement currency must match the destination account currency.']);
            }
            $this->assertSettlementAllocations($companyId, $data['allocations'] ?? [], (int) $data['gross_amount']);
            $settlement = GatewaySettlement::query()->create([...$data, 'company_id' => $companyId, 'status' => 'draft', 'idempotency_key' => $idempotencyKey, 'idempotency_hash' => $hash, 'created_by' => $user->id]);
            foreach ($data['allocations'] ?? [] as $allocation) {
                $settlement->allocations()->create(['company_id' => $companyId, ...$allocation]);
            }
            if (($data['post'] ?? false) === true) {
                $settlement = $this->postSettlement($companyId, $user, $settlement);
            }

            return $settlement->load(['destinationAccount', 'allocations', 'journal.lines.account']);
        }, 3);
    }

    public function postSettlement(string $companyId, User $user, GatewaySettlement $settlement): GatewaySettlement
    {
        return DB::transaction(function () use ($companyId, $user, $settlement): GatewaySettlement {
            $settlement = GatewaySettlement::query()->where('company_id', $companyId)->with('destinationAccount')->lockForUpdate()->findOrFail($settlement->id);
            if ($settlement->status === 'posted') {
                return $settlement->load(['allocations', 'journal.lines.account']);
            }
            $lines = [
                ['account_id' => $settlement->destinationAccount->gl_account_id, 'description' => 'Gateway net settlement', 'debit' => $settlement->net_amount, 'credit' => 0, 'related_type' => 'gateway_settlement', 'related_id' => $settlement->id],
                ['account_id' => $settlement->clearing_account_id, 'description' => 'Gateway clearing', 'debit' => 0, 'credit' => $settlement->gross_amount, 'related_type' => 'gateway_settlement', 'related_id' => $settlement->id],
            ];
            if ($settlement->fee_amount > 0) {
                $lines[] = ['account_id' => $settlement->fee_account_id, 'description' => 'Gateway fee', 'debit' => $settlement->fee_amount, 'credit' => 0, 'related_type' => 'gateway_settlement', 'related_id' => $settlement->id];
            }
            if ($settlement->adjustment_amount !== 0) {
                $lines[] = ['account_id' => $settlement->fee_account_id, 'description' => 'Gateway adjustment', 'debit' => $settlement->adjustment_amount < 0 ? abs($settlement->adjustment_amount) : 0, 'credit' => $settlement->adjustment_amount > 0 ? $settlement->adjustment_amount : 0, 'related_type' => 'gateway_settlement', 'related_id' => $settlement->id];
            }
            $journal = $this->journalPostingService->post($companyId, $user, ['posting_date' => $settlement->settlement_date->toDateString(), 'reference' => $settlement->settlement_reference, 'reference_type' => 'gateway_settlement', 'source_id' => $settlement->id, 'source' => 'banking', 'description' => "{$settlement->provider} gateway settlement", 'lines' => $lines], 'gateway-settlement:'.hash('sha256', $settlement->id));
            $settlement->update(['status' => 'posted', 'journal_id' => $journal->id, 'posted_by' => $user->id, 'posted_at' => now()]);

            return $settlement->fresh(['destinationAccount', 'allocations', 'journal.lines.account']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function customerReceipt(string $companyId, User $user, BankTransaction $transaction, Invoice $invoice, array $data, string $idempotencyKey): CustomerPayment
    {
        if ($transaction->direction->value !== 'credit') {
            throw ValidationException::withMessages(['bank_transaction' => 'A customer receipt requires a bank credit.']);
        }
        $invoice = $this->customerPaymentService->record($companyId, $user, $invoice, ['amount' => (int) ($data['amount'] ?? $transaction->amount), 'payment_date' => $transaction->transaction_date->toDateString(), 'method' => 'bank_transfer', 'bank_account_id' => $transaction->financialAccount->gl_account_id, 'reference' => $transaction->bank_reference, 'note' => $data['note'] ?? 'Created from bank evidence'], 'bank-receipt:'.$idempotencyKey);
        $payment = CustomerPayment::query()->where('company_id', $companyId)->where('idempotency_key', 'bank-receipt:'.$idempotencyKey)->firstOrFail();
        $this->matchingService->match($companyId, $user, $transaction, ['matchable_type' => 'customer_payment', 'matchable_id' => $payment->id, 'amount' => $payment->amount, 'reason' => 'Customer receipt created from bank evidence'], 'bank-receipt-match:'.$idempotencyKey);

        return $payment->load(['invoice', 'journal.lines.account']);
    }

    /** @param array<string, mixed> $data */
    public function supplierPayment(string $companyId, User $user, BankTransaction $transaction, SupplierBill $bill, array $data, string $idempotencyKey): SupplierPayment
    {
        if ($transaction->direction->value !== 'debit') {
            throw ValidationException::withMessages(['bank_transaction' => 'A supplier payment requires a bank debit.']);
        }
        $this->supplierPaymentService->record($companyId, $user, $bill, ['amount' => (int) ($data['amount'] ?? $transaction->amount), 'payment_date' => $transaction->transaction_date->toDateString(), 'posting_date' => $transaction->transaction_date->toDateString(), 'method' => 'bank_transfer', 'bank_account_id' => $transaction->financialAccount->gl_account_id, 'reference' => $transaction->bank_reference, 'note' => $data['note'] ?? 'Created from bank evidence'], 'bank-payment:'.$idempotencyKey);
        $payment = SupplierPayment::query()->where('company_id', $companyId)->where('idempotency_key', 'bank-payment:'.$idempotencyKey)->firstOrFail();
        $this->matchingService->match($companyId, $user, $transaction, ['matchable_type' => 'supplier_payment', 'matchable_id' => $payment->id, 'amount' => $payment->amount, 'reason' => 'Supplier payment created from bank evidence'], 'bank-payment-match:'.$idempotencyKey);

        return $payment->load(['allocations', 'journal.lines.account']);
    }

    /** @param array<string, mixed> $data */
    public function createReconciliation(string $companyId, User $user, array $data, string $idempotencyKey): BankReconciliation
    {
        return DB::transaction(function () use ($companyId, $user, $data, $idempotencyKey): BankReconciliation {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = BankReconciliation::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $this->assertSameHash($existing, $hash, 'bank reconciliation');

                return $existing->load(['financialAccount', 'statementImport', 'matches']);
            }
            $account = FinancialAccount::query()->where('company_id', $companyId)->where('type', 'bank')->findOrFail($data['financial_account_id']);
            if (isset($data['bank_statement_import_id'])) {
                $account->statementImports()->where('company_id', $companyId)->where('status', 'confirmed')->findOrFail($data['bank_statement_import_id']);
            }
            $book = $this->reportingService->bookBalance($account->load('glAccount'), $data['period_end']);
            $sequence = (int) BankReconciliation::query()->where('company_id', $companyId)->max('sequence') + 1;
            $reconciliation = BankReconciliation::query()->create([...$data, 'company_id' => $companyId, 'sequence' => $sequence, 'number' => sprintf('REC-%s-%04d', date('Y', strtotime($data['period_end'])), $sequence), 'book_balance' => $book, 'difference' => (int) $data['statement_closing_balance'] - $book, 'status' => 'draft', 'idempotency_key' => $idempotencyKey, 'idempotency_hash' => $hash, 'created_by' => $user->id]);

            return $reconciliation->load(['financialAccount', 'statementImport', 'matches']);
        }, 3);
    }

    public function completeReconciliation(string $companyId, User $user, BankReconciliation $reconciliation): BankReconciliation
    {
        return DB::transaction(function () use ($companyId, $user, $reconciliation): BankReconciliation {
            $reconciliation = BankReconciliation::query()->where('company_id', $companyId)->with('financialAccount.glAccount')->lockForUpdate()->findOrFail($reconciliation->id);
            if ($reconciliation->status === 'completed') {
                return $reconciliation;
            }
            $book = $this->reportingService->bookBalance($reconciliation->financialAccount, $reconciliation->period_end->toDateString());
            $difference = $reconciliation->statement_closing_balance - $book;
            $unmatched = BankTransaction::query()->where('company_id', $companyId)->where('financial_account_id', $reconciliation->financial_account_id)->whereBetween('transaction_date', [$reconciliation->period_start, $reconciliation->period_end])->whereIn('status', ['unmatched', 'suggested', 'partially_matched'])->exists();
            if ($difference !== 0 || $unmatched) {
                throw ValidationException::withMessages(['reconciliation' => 'Reconciliation can only complete when the statement equals book balance and no statement items remain unmatched.']);
            }
            $reconciliation->update(['book_balance' => $book, 'difference' => 0, 'status' => 'completed', 'completed_by' => $user->id, 'completed_at' => now()]);

            return $reconciliation->fresh(['financialAccount', 'statementImport', 'matches']);
        }, 3);
    }

    public function reopenReconciliation(string $companyId, User $user, BankReconciliation $reconciliation, string $reason): BankReconciliation
    {
        return DB::transaction(function () use ($companyId, $user, $reconciliation, $reason): BankReconciliation {
            $reconciliation = BankReconciliation::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($reconciliation->id);
            if ($reconciliation->status !== 'completed') {
                throw ValidationException::withMessages(['reconciliation' => 'Only a completed reconciliation can be reopened.']);
            }
            $reconciliation->update(['status' => 'reopened', 'reopened_by' => $user->id, 'reopened_at' => now(), 'reopen_reason' => $reason]);

            return $reconciliation->fresh(['financialAccount', 'statementImport', 'matches']);
        }, 3);
    }

    private function assertSameHash(Model $model, string $hash, string $operation): void
    {
        if (! hash_equals((string) $model->getAttribute('idempotency_hash'), $hash)) {
            throw new ConflictHttpException("The idempotency key has already been used for a different {$operation} request.");
        }
    }

    /** @param array<int, array<string, mixed>> $allocations */
    private function assertSettlementAllocations(string $companyId, array $allocations, int $gross): void
    {
        if (array_sum(array_column($allocations, 'amount')) > $gross) {
            throw ValidationException::withMessages(['allocations' => 'Settlement allocations cannot exceed the gross amount.']);
        }
        foreach ($allocations as $index => $allocation) {
            if ($allocation['source_type'] !== 'customer_payment' || ! CustomerPayment::query()->where('company_id', $companyId)->whereKey($allocation['source_id'])->exists()) {
                throw ValidationException::withMessages(["allocations.{$index}.source_id" => 'The settlement source must be a company customer payment.']);
            }
        }
    }
}
