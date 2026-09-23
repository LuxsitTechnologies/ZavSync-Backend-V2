<?php

namespace App\Services\Accounting;

use App\Enums\FbrSubmissionStatus;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class InvoiceService
{
    public function __construct(private readonly InvoiceCalculationService $calculationService) {}

    /** @param array<string, mixed> $data */
    public function create(string $companyId, User $user, array $data, string $idempotencyKey): Invoice
    {
        return DB::transaction(function () use ($companyId, $user, $data, $idempotencyKey): Invoice {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $hash = $this->hash($data);
            $existing = Invoice::query()->where('company_id', $companyId)->where('creation_idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->creation_idempotency_hash, $hash)) {
                    throw new ConflictHttpException('The idempotency key has already been used for a different invoice request.');
                }

                return $existing->load(['customer', 'lines', 'journal']);
            }
            $this->customer($companyId, (string) $data['customer_id']);
            $calculation = $this->calculationService->calculate($data['lines']);
            $sequence = (int) Invoice::query()->where('company_id', $companyId)->max('sequence') + 1;
            $invoice = Invoice::query()->create([
                ...$this->header($data), ...$calculation['totals'], 'company_id' => $companyId, 'sequence' => $sequence,
                'invoice_number' => sprintf('INV-%s-%04d', date('Y', strtotime($data['invoice_date'])), $sequence),
                'status' => InvoiceStatus::Draft, 'fbr_status' => FbrSubmissionStatus::NotSubmitted,
                'amount_paid' => 0, 'balance_due' => $calculation['totals']['total'],
                'creation_idempotency_key' => $idempotencyKey, 'creation_idempotency_hash' => $hash, 'created_by' => $user->id,
            ]);
            $invoice->lines()->createMany($calculation['lines']);

            return $invoice->load(['customer', 'lines', 'journal']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(string $companyId, User $user, Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($companyId, $user, $invoice, $data): Invoice {
            $invoice = Invoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw ValidationException::withMessages(['invoice' => 'Posted invoice financial fields are immutable. Void and reverse the invoice to correct it.']);
            }
            $this->customer($companyId, (string) $data['customer_id']);
            $calculation = $this->calculationService->calculate($data['lines']);
            $invoice->update([...$this->header($data), ...$calculation['totals'], 'balance_due' => $calculation['totals']['total'], 'updated_by' => $user->id]);
            $invoice->lines()->delete();
            $invoice->lines()->createMany($calculation['lines']);

            return $invoice->load(['customer', 'lines', 'journal']);
        });
    }

    public function deleteDraft(string $companyId, Invoice $invoice): void
    {
        DB::transaction(function () use ($companyId, $invoice): void {
            $invoice = Invoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw ValidationException::withMessages(['invoice' => 'Only a draft invoice can be deleted.']);
            }
            $invoice->delete();
        });
    }

    private function customer(string $companyId, string $customerId): Customer
    {
        return Customer::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($customerId);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function header(array $data): array
    {
        return ['customer_id' => $data['customer_id'], 'invoice_date' => $data['invoice_date'], 'due_date' => $data['due_date'], 'currency' => $data['currency'], 'notes' => $data['notes'] ?? null, 'terms' => $data['terms'] ?? null];
    }

    /** @param array<string, mixed> $data */
    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
