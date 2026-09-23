<?php

namespace App\Services\Accounting;

use App\Enums\SupplierBillStatus;
use App\Models\Company;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierBill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierBillPostingService
{
    public function __construct(private readonly JournalPostingService $journalPostingService, private readonly AccountMappingService $mappingService, private readonly PurchaseCalculationService $calculationService) {}

    public function post(string $companyId, User $user, SupplierBill $bill): SupplierBill
    {
        return DB::transaction(function () use ($companyId, $user, $bill): SupplierBill {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $bill = SupplierBill::query()->where('company_id', $companyId)->with(['supplier', 'lines'])->lockForUpdate()->findOrFail($bill->id);
            if ($bill->journal_id !== null) {
                return $this->load($bill);
            }
            if ($bill->status !== SupplierBillStatus::Draft || $bill->total <= 0) {
                throw ValidationException::withMessages(['supplier_bill' => 'Only a positive draft supplier bill can be posted.']);
            }

            $inputLines = $bill->lines->map(fn ($line): array => [
                'purchase_order_line_id' => $line->purchase_order_line_id, 'purchase_receipt_line_id' => $line->purchase_receipt_line_id,
                'item_id' => $line->item_id, 'item_name' => $line->item_name, 'description' => $line->description,
                'procurement_type' => $line->procurement_type, 'quantity_milli' => $line->quantity_milli, 'unit' => $line->unit,
                'unit_price' => $line->unit_price, 'discount' => $line->discount, 'tax_rate_bps' => $line->tax_rate_bps,
                'withholding_rate_bps' => $line->withholding_rate_bps, 'expense_account_id' => $line->expense_account_id,
                'metadata' => $line->metadata,
            ])->all();
            $recalculated = $this->calculationService->calculate($inputLines, true);
            $totals = $recalculated['totals'];
            foreach ($bill->lines as $index => $line) {
                $line->update($recalculated['lines'][$index]);
            }
            $bill->update([
                'subtotal' => $totals['subtotal'], 'discount' => $totals['discount'], 'taxable_amount' => $totals['taxable_amount'],
                'purchase_tax' => $totals['tax'], 'withholding_tax' => $totals['withholding_tax'],
                'gross_total' => $totals['gross_total'], 'total' => $totals['total'], 'balance_due' => $totals['total'],
            ]);

            $payable = $this->mappingService->require($companyId, 'accounts_payable');
            $journalLines = [];
            foreach ($bill->lines as $line) {
                $journalLines[] = ['account_id' => $line->expense_account_id, 'description' => $line->description, 'debit' => $line->taxable_amount, 'credit' => 0, 'related_type' => 'supplier_bill', 'related_id' => $bill->id];
            }
            if ($bill->purchase_tax > 0) {
                $tax = $this->mappingService->require($companyId, 'purchase_tax_recoverable');
                $journalLines[] = ['account_id' => $tax->id, 'description' => "Purchase tax — {$bill->bill_number}", 'debit' => $bill->purchase_tax, 'credit' => 0, 'related_type' => 'supplier_bill', 'related_id' => $bill->id];
            }
            $journalLines[] = ['account_id' => $payable->id, 'description' => "Payable — {$bill->supplier->name}", 'debit' => 0, 'credit' => $bill->total, 'related_type' => 'supplier_bill', 'related_id' => $bill->id];
            if ($bill->withholding_tax > 0) {
                $withholding = $this->mappingService->require($companyId, 'withholding_tax_payable');
                $journalLines[] = ['account_id' => $withholding->id, 'description' => "Withholding tax — {$bill->bill_number}", 'debit' => 0, 'credit' => $bill->withholding_tax, 'related_type' => 'supplier_bill', 'related_id' => $bill->id];
            }
            $journal = $this->journalPostingService->post($companyId, $user, [
                'posting_date' => $bill->posting_date->format('Y-m-d'), 'reference' => $bill->supplier_invoice_number,
                'reference_type' => 'supplier_bill', 'source_id' => $bill->id, 'source' => 'supplier_bill',
                'description' => "Supplier bill — {$bill->supplier->name}", 'lines' => $journalLines,
            ], "supplier-bill-post:{$bill->id}");
            $bill->update(['status' => SupplierBillStatus::Unpaid, 'journal_id' => $journal->id, 'posted_by' => $user->id, 'posted_at' => now(), 'balance_due' => $bill->total]);

            return $this->load($bill);
        });
    }

    public function void(string $companyId, User $user, SupplierBill $bill, string $postingDate, string $reason): SupplierBill
    {
        return DB::transaction(function () use ($companyId, $user, $bill, $postingDate, $reason): SupplierBill {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $bill = SupplierBill::query()->where('company_id', $companyId)->with('lines')->lockForUpdate()->findOrFail($bill->id);
            if ($bill->journal_id === null || $bill->status === SupplierBillStatus::Void) {
                throw ValidationException::withMessages(['supplier_bill' => 'Only a posted supplier bill can be voided.']);
            }
            if ($bill->amount_paid > 0) {
                throw ValidationException::withMessages(['supplier_bill' => 'Reverse or reallocate supplier payments before voiding this bill.']);
            }
            $journal = $this->journalPostingService->reverse($companyId, $user, $bill->journal()->firstOrFail(), $postingDate, $reason, "supplier-bill-void:{$bill->id}");
            foreach ($bill->lines as $line) {
                if ($line->purchase_order_line_id !== null) {
                    PurchaseOrderLine::query()->lockForUpdate()->findOrFail($line->purchase_order_line_id)->decrement('billed_quantity_milli', $line->quantity_milli);
                }
            }
            $bill->update(['status' => SupplierBillStatus::Void, 'balance_due' => 0, 'reversal_journal_id' => $journal->id, 'voided_by' => $user->id, 'voided_at' => now()]);

            return $this->load($bill);
        });
    }

    private function load(SupplierBill $bill): SupplierBill
    {
        return $bill->load(['supplier', 'purchaseOrder', 'purchaseReceipt', 'lines.expenseAccount', 'journal', 'reversalJournal', 'allocations.payment']);
    }
}
