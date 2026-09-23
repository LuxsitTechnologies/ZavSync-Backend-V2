<?php

namespace App\Services\Accounting;

use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierBillStatus;
use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReceiptLine;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SupplierBillService
{
    public function __construct(private readonly PurchaseCalculationService $calculationService) {}

    /** @param array<string,mixed> $data */
    public function create(string $companyId, User $user, array $data, string $idempotencyKey): SupplierBill
    {
        return DB::transaction(function () use ($companyId, $user, $data, $idempotencyKey): SupplierBill {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $hash = $this->hash($data);
            $existing = SupplierBill::query()->where('company_id', $companyId)->where('creation_idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->creation_idempotency_hash, $hash)) {
                    throw new ConflictHttpException('The idempotency key has already been used for a different supplier bill.');
                }

                return $this->load($existing);
            }
            $this->supplier($companyId, (string) $data['supplier_id']);
            $this->validateAndReserveLinkedQuantities($companyId, $data);
            $calculation = $this->calculationService->calculate($data['lines'], true);
            $sequence = (int) SupplierBill::query()->where('company_id', $companyId)->max('sequence') + 1;
            $bill = SupplierBill::query()->create([
                ...$this->header($data), ...$this->totals($calculation['totals']), 'company_id' => $companyId,
                'sequence' => $sequence, 'bill_number' => sprintf('BILL-%s-%04d', date('Y', strtotime($data['bill_date'])), $sequence),
                'status' => SupplierBillStatus::Draft, 'amount_paid' => 0, 'balance_due' => $calculation['totals']['total'],
                'creation_idempotency_key' => $idempotencyKey, 'creation_idempotency_hash' => $hash, 'created_by' => $user->id,
            ]);
            $bill->lines()->createMany($calculation['lines']);

            return $this->load($bill);
        });
    }

    /** @param array<string,mixed> $data */
    public function update(string $companyId, User $user, SupplierBill $bill, array $data): SupplierBill
    {
        return DB::transaction(function () use ($companyId, $user, $bill, $data): SupplierBill {
            $bill = SupplierBill::query()->where('company_id', $companyId)->with('lines')->lockForUpdate()->findOrFail($bill->id);
            if ($bill->status !== SupplierBillStatus::Draft) {
                throw ValidationException::withMessages(['supplier_bill' => 'Posted supplier bill financial fields are immutable. Void and reverse the bill to correct it.']);
            }
            $this->supplier($companyId, (string) $data['supplier_id']);
            $this->releaseLinkedQuantities($bill);
            $this->validateAndReserveLinkedQuantities($companyId, $data);
            $calculation = $this->calculationService->calculate($data['lines'], true);
            $bill->update([...$this->header($data), ...$this->totals($calculation['totals']), 'balance_due' => $calculation['totals']['total'], 'updated_by' => $user->id]);
            $bill->lines()->delete();
            $bill->lines()->createMany($calculation['lines']);

            return $this->load($bill);
        });
    }

    public function deleteDraft(string $companyId, SupplierBill $bill): void
    {
        DB::transaction(function () use ($companyId, $bill): void {
            $bill = SupplierBill::query()->where('company_id', $companyId)->with('lines')->lockForUpdate()->findOrFail($bill->id);
            if ($bill->status !== SupplierBillStatus::Draft) {
                throw ValidationException::withMessages(['supplier_bill' => 'Only a draft supplier bill can be deleted.']);
            }
            $this->releaseLinkedQuantities($bill);
            $bill->delete();
        });
    }

    /** @param array<string,mixed> $data */
    private function validateAndReserveLinkedQuantities(string $companyId, array $data): void
    {
        $orderId = $data['purchase_order_id'] ?? null;
        if ($orderId !== null) {
            $order = PurchaseOrder::query()->where('company_id', $companyId)->where('supplier_id', $data['supplier_id'])->lockForUpdate()->findOrFail($orderId);
            if (! in_array($order->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Received], true)) {
                throw ValidationException::withMessages(['purchase_order_id' => 'Only an approved or received purchase order can be billed.']);
            }
            if (($data['purchase_receipt_id'] ?? null) !== null && ! $order->receipts()->whereKey($data['purchase_receipt_id'])->exists()) {
                throw ValidationException::withMessages(['purchase_receipt_id' => 'The receipt does not belong to the selected purchase order.']);
            }
        }
        foreach ($data['lines'] as $index => $input) {
            $lineId = $input['purchase_order_line_id'] ?? null;
            if ($lineId === null) {
                if (($input['purchase_receipt_line_id'] ?? null) !== null || $orderId !== null) {
                    throw ValidationException::withMessages(["lines.$index.purchase_order_line_id" => 'A purchase-order line is required for a linked supplier bill.']);
                }

                continue;
            }
            /** @var PurchaseOrderLine|null $orderLine */
            $orderLine = PurchaseOrderLine::query()->where('purchase_order_id', $orderId)->lockForUpdate()->find($lineId);
            if ($orderLine === null) {
                throw ValidationException::withMessages(["lines.$index.purchase_order_line_id" => 'The line does not belong to the selected purchase order.']);
            }
            $quantity = (int) $input['quantity_milli'];
            if ($quantity > $orderLine->received_quantity_milli - $orderLine->billed_quantity_milli) {
                throw ValidationException::withMessages(["lines.$index.quantity_milli" => 'The billed quantity cannot exceed the received, unbilled quantity.']);
            }
            $receiptLineId = $input['purchase_receipt_line_id'] ?? null;
            if ($receiptLineId !== null) {
                $receiptLine = PurchaseReceiptLine::query()->where('id', $receiptLineId)->where('purchase_order_line_id', $lineId)->whereHas('receipt', fn ($query) => $query->where('company_id', $companyId))->first();
                if ($receiptLine === null) {
                    throw ValidationException::withMessages(["lines.$index.purchase_receipt_line_id" => 'The receipt line does not belong to this purchase-order line.']);
                }
                $alreadyBilled = (int) $receiptLine->billLines()->whereHas('bill', fn ($query) => $query->where('status', '!=', SupplierBillStatus::Void->value))->sum('quantity_milli');
                if ($quantity > $receiptLine->quantity_received_milli - $alreadyBilled) {
                    throw ValidationException::withMessages(["lines.$index.quantity_milli" => 'The billed quantity cannot exceed the unbilled quantity on the selected receipt line.']);
                }
            }
            $orderLine->increment('billed_quantity_milli', $quantity);
        }
    }

    private function releaseLinkedQuantities(SupplierBill $bill): void
    {
        foreach ($bill->lines as $line) {
            if ($line->purchase_order_line_id !== null) {
                $orderLine = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($line->purchase_order_line_id);
                $orderLine->decrement('billed_quantity_milli', $line->quantity_milli);
            }
        }
    }

    private function supplier(string $companyId, string $supplierId): Supplier
    {
        return Supplier::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($supplierId);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function header(array $data): array
    {
        return ['supplier_id' => $data['supplier_id'], 'purchase_order_id' => $data['purchase_order_id'] ?? null, 'purchase_receipt_id' => $data['purchase_receipt_id'] ?? null, 'supplier_invoice_number' => $data['supplier_invoice_number'], 'bill_date' => $data['bill_date'], 'posting_date' => $data['posting_date'], 'due_date' => $data['due_date'], 'currency' => $data['currency'], 'notes' => $data['notes'] ?? null];
    }

    /** @param array<string,int> $totals @return array<string,int> */
    private function totals(array $totals): array
    {
        return ['subtotal' => $totals['subtotal'], 'discount' => $totals['discount'], 'taxable_amount' => $totals['taxable_amount'], 'purchase_tax' => $totals['tax'], 'withholding_tax' => $totals['withholding_tax'], 'gross_total' => $totals['gross_total'], 'total' => $totals['total']];
    }

    private function load(SupplierBill $bill): SupplierBill
    {
        return $bill->load(['supplier', 'purchaseOrder', 'purchaseReceipt', 'lines.expenseAccount', 'journal', 'reversalJournal', 'allocations.payment']);
    }

    /** @param array<string,mixed> $data */
    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
