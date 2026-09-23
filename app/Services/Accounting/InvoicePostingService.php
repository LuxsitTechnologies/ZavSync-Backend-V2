<?php

namespace App\Services\Accounting;

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoicePostingService
{
    public function __construct(private readonly JournalPostingService $journalPostingService, private readonly AccountMappingService $mappingService) {}

    public function post(string $companyId, User $user, Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($companyId, $user, $invoice): Invoice {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $invoice = Invoice::query()->where('company_id', $companyId)->with('customer')->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->journal_id !== null) {
                return $invoice->load(['customer', 'lines', 'journal']);
            }
            if ($invoice->status !== InvoiceStatus::Draft || $invoice->total <= 0) {
                throw ValidationException::withMessages(['invoice' => 'Only a positive draft invoice can be posted.']);
            }
            $receivable = $this->mappingService->require($companyId, 'accounts_receivable');
            $revenue = $this->mappingService->require($companyId, 'sales_revenue');
            $lines = [
                ['account_id' => $receivable->id, 'description' => "Receivable — {$invoice->customer->name}", 'debit' => $invoice->total, 'credit' => 0, 'related_type' => 'invoice', 'related_id' => $invoice->id],
                ['account_id' => $revenue->id, 'description' => "Revenue — {$invoice->invoice_number}", 'debit' => 0, 'credit' => $invoice->taxable_amount, 'related_type' => 'invoice', 'related_id' => $invoice->id],
            ];
            $this->appendCredit($lines, $companyId, 'sales_tax_payable', $invoice->sales_tax, 'Sales tax', $invoice);
            $this->appendCredit($lines, $companyId, 'other_tax_payable', $invoice->other_tax, 'Other tax', $invoice);
            $this->appendCredit($lines, $companyId, 'advance_tax_payable', $invoice->advance_tax, 'Advance tax', $invoice);
            if ($invoice->withholding_tax > 0) {
                $account = $this->mappingService->require($companyId, 'withholding_tax_receivable');
                $lines[] = ['account_id' => $account->id, 'description' => "Withholding tax — {$invoice->invoice_number}", 'debit' => $invoice->withholding_tax, 'credit' => 0, 'related_type' => 'invoice', 'related_id' => $invoice->id];
            }
            $journal = $this->journalPostingService->post($companyId, $user, [
                'posting_date' => $invoice->invoice_date->format('Y-m-d'), 'reference' => $invoice->invoice_number,
                'reference_type' => 'invoice', 'source_id' => $invoice->id, 'source' => 'invoice',
                'description' => "Sales invoice — {$invoice->customer->name}", 'lines' => $lines,
            ], "invoice-post:{$invoice->id}");
            $invoice->update(['status' => InvoiceStatus::Unpaid, 'journal_id' => $journal->id, 'posted_by' => $user->id, 'posted_at' => now(), 'balance_due' => $invoice->total]);

            return $invoice->load(['customer', 'lines', 'journal']);
        });
    }

    public function void(string $companyId, User $user, Invoice $invoice, string $postingDate, string $reason): Invoice
    {
        return DB::transaction(function () use ($companyId, $user, $invoice, $postingDate, $reason): Invoice {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $invoice = Invoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->journal_id === null || $invoice->status === InvoiceStatus::Void) {
                throw ValidationException::withMessages(['invoice' => 'Only a posted invoice can be voided.']);
            }
            if ($invoice->amount_paid > 0) {
                throw ValidationException::withMessages(['invoice' => 'Reverse or reallocate customer payments before voiding this invoice.']);
            }
            $journal = $this->journalPostingService->reverse($companyId, $user, $invoice->journal()->firstOrFail(), $postingDate, $reason, "invoice-void:{$invoice->id}");
            $invoice->update(['status' => InvoiceStatus::Void, 'balance_due' => 0, 'reversal_journal_id' => $journal->id, 'voided_by' => $user->id, 'voided_at' => now()]);

            return $invoice->load(['customer', 'lines', 'journal', 'reversalJournal']);
        });
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function appendCredit(array &$lines, string $companyId, string $mappingKey, int $amount, string $label, Invoice $invoice): void
    {
        if ($amount === 0) {
            return;
        }
        $account = $this->mappingService->require($companyId, $mappingKey);
        $lines[] = ['account_id' => $account->id, 'description' => "$label — {$invoice->invoice_number}", 'debit' => 0, 'credit' => $amount, 'related_type' => 'invoice', 'related_id' => $invoice->id];
    }
}
