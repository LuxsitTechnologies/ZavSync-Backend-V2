<?php

namespace App\Services\Accounting;

use App\Enums\SupplierBillStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SupplierPaymentService
{
    public function __construct(private readonly JournalPostingService $journalPostingService, private readonly AccountMappingService $mappingService) {}

    /** @param array<string,mixed> $data */
    public function record(string $companyId, User $user, SupplierBill $bill, array $data, string $idempotencyKey): SupplierBill
    {
        return DB::transaction(function () use ($companyId, $user, $bill, $data, $idempotencyKey): SupplierBill {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $hash = hash('sha256', json_encode(['supplier_bill_id' => $bill->id, ...$data], JSON_THROW_ON_ERROR));
            $existing = SupplierPayment::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->idempotency_hash, $hash)) {
                    throw new ConflictHttpException('The idempotency key has already been used for a different supplier payment.');
                }

                return $this->load($existing->allocations()->firstOrFail()->bill);
            }
            $bill = SupplierBill::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($bill->id);
            if (! $bill->status->acceptsPayments() || $bill->journal_id === null) {
                throw ValidationException::withMessages(['supplier_bill' => 'Payments can only be recorded against a posted bill with an outstanding balance.']);
            }
            $amount = (int) $data['amount'];
            if ($amount > $bill->balance_due) {
                throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the outstanding balance on this bill.']);
            }
            $bankAccount = Account::query()->where('company_id', $companyId)->where('type', 'asset')->where('is_active', true)->findOrFail($data['bank_account_id']);
            $expectedKey = $data['method'] === 'cash' ? 'cash' : 'bank';
            if ($bankAccount->id !== $this->mappingService->require($companyId, $expectedKey)->id) {
                throw ValidationException::withMessages(['bank_account_id' => "Select the configured {$expectedKey} account for this payment."]);
            }
            $payable = $this->mappingService->require($companyId, 'accounts_payable');
            $sequence = (int) SupplierPayment::query()->where('company_id', $companyId)->max('sequence') + 1;
            $number = sprintf('PAY-%s-%04d', date('Y', strtotime($data['payment_date'])), $sequence);
            $paymentId = (string) Str::uuid();
            $journal = $this->journalPostingService->post($companyId, $user, [
                'posting_date' => $data['posting_date'], 'reference' => $number, 'reference_type' => 'supplier_payment',
                'source_id' => $paymentId, 'source' => 'supplier_payment', 'description' => "Supplier payment — {$bill->bill_number}",
                'lines' => [
                    ['account_id' => $payable->id, 'description' => "Settle payable — {$bill->bill_number}", 'debit' => $amount, 'credit' => 0, 'related_type' => 'supplier_payment', 'related_id' => $paymentId],
                    ['account_id' => $bankAccount->id, 'description' => "Payment — {$bill->bill_number}", 'debit' => 0, 'credit' => $amount, 'related_type' => 'supplier_payment', 'related_id' => $paymentId],
                ],
            ], 'supplier-payment:'.hash('sha256', $idempotencyKey));
            $payment = new SupplierPayment([
                'company_id' => $companyId, 'supplier_id' => $bill->supplier_id, 'sequence' => $sequence, 'number' => $number,
                'payment_date' => $data['payment_date'], 'posting_date' => $data['posting_date'], 'amount' => $amount,
                'method' => $data['method'], 'bank_account_id' => $bankAccount->id, 'reference' => $data['reference'] ?? null,
                'notes' => $data['note'] ?? null, 'idempotency_key' => $idempotencyKey, 'idempotency_hash' => $hash,
                'journal_id' => $journal->id, 'created_by' => $user->id,
            ]);
            $payment->id = $paymentId;
            $payment->save();
            $payment->allocations()->create(['supplier_bill_id' => $bill->id, 'amount' => $amount]);
            $paid = $bill->amount_paid + $amount;
            $balance = $bill->total - $paid;
            $bill->update(['amount_paid' => $paid, 'balance_due' => $balance, 'status' => $balance === 0 ? SupplierBillStatus::Paid : SupplierBillStatus::PartiallyPaid]);

            return $this->load($bill);
        });
    }

    private function load(SupplierBill $bill): SupplierBill
    {
        return $bill->load(['supplier', 'lines.expenseAccount', 'journal', 'allocations.payment']);
    }
}
