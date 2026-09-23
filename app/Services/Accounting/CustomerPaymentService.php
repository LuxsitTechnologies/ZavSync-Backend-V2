<?php

namespace App\Services\Accounting;

use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CustomerPayment;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CustomerPaymentService
{
    public function __construct(private readonly JournalPostingService $journalPostingService, private readonly AccountMappingService $mappingService) {}

    /** @param array<string, mixed> $data */
    public function record(string $companyId, User $user, Invoice $invoice, array $data, string $idempotencyKey): Invoice
    {
        return DB::transaction(function () use ($companyId, $user, $invoice, $data, $idempotencyKey): Invoice {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = CustomerPayment::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->idempotency_hash, $hash)) {
                    throw new ConflictHttpException('The idempotency key has already been used for a different payment request.');
                }

                return $existing->invoice()->with(['customer', 'lines', 'journal'])->firstOrFail();
            }
            $invoice = Invoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id);
            if (! $invoice->status->acceptsPayments() || $invoice->journal_id === null) {
                throw ValidationException::withMessages(['invoice' => 'Payments can only be recorded against a posted invoice with an outstanding balance.']);
            }
            $amount = (int) $data['amount'];
            if ($amount > $invoice->balance_due) {
                throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the outstanding balance on this invoice.']);
            }
            $bankAccount = Account::query()->where('company_id', $companyId)->where('type', 'asset')->where('is_active', true)->findOrFail($data['bank_account_id']);
            $expectedKey = $data['method'] === 'cash' ? 'cash' : 'bank';
            $defaultAccount = $this->mappingService->require($companyId, $expectedKey);
            $isLinkedFinancialAccount = FinancialAccount::query()->where('company_id', $companyId)->where('gl_account_id', $bankAccount->id)->where('type', $expectedKey)->where('is_active', true)->exists();
            if ($bankAccount->id !== $defaultAccount->id && ! $isLinkedFinancialAccount) {
                throw ValidationException::withMessages(['bank_account_id' => "Select the configured {$expectedKey} account for this receipt."]);
            }
            $receivable = $this->mappingService->require($companyId, 'accounts_receivable');
            $sequence = (int) CustomerPayment::query()->where('company_id', $companyId)->max('sequence') + 1;
            $number = sprintf('RCPT-%s-%04d', date('Y', strtotime($data['payment_date'])), $sequence);
            $paymentId = (string) Str::uuid();
            $journal = $this->journalPostingService->post($companyId, $user, [
                'posting_date' => $data['payment_date'], 'reference' => $number, 'reference_type' => 'customer_payment',
                'source_id' => $paymentId, 'source' => 'customer_payment', 'description' => "Customer receipt — {$invoice->invoice_number}",
                'lines' => [
                    ['account_id' => $bankAccount->id, 'description' => "Receipt — {$invoice->invoice_number}", 'debit' => $amount, 'credit' => 0, 'related_type' => 'customer_payment', 'related_id' => $paymentId],
                    ['account_id' => $receivable->id, 'description' => "Settle receivable — {$invoice->invoice_number}", 'debit' => 0, 'credit' => $amount, 'related_type' => 'customer_payment', 'related_id' => $paymentId],
                ],
            ], 'customer-payment:'.hash('sha256', $idempotencyKey));
            $payment = new CustomerPayment([
                'company_id' => $companyId, 'customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id,
                'sequence' => $sequence, 'number' => $number, 'payment_date' => $data['payment_date'], 'amount' => $amount,
                'method' => $data['method'], 'bank_account_id' => $bankAccount->id, 'reference' => $data['reference'] ?? null,
                'notes' => $data['note'] ?? null, 'idempotency_key' => $idempotencyKey, 'idempotency_hash' => $hash,
                'journal_id' => $journal->id, 'created_by' => $user->id,
            ]);
            $payment->id = $paymentId;
            $payment->save();
            $paid = $invoice->amount_paid + $amount;
            $balance = $invoice->total - $paid;
            $invoice->update(['amount_paid' => $paid, 'balance_due' => $balance, 'status' => $balance === 0 ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid, 'updated_by' => $user->id]);

            return $invoice->load(['customer', 'lines', 'journal']);
        });
    }
}
