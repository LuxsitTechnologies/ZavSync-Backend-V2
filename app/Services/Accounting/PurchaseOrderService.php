<?php

namespace App\Services\Accounting;

use App\Enums\PurchaseOrderStatus;
use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchaseOrderService
{
    public function __construct(private readonly PurchaseCalculationService $calculationService) {}

    /** @param array<string,mixed> $data */
    public function create(string $companyId, User $user, array $data, string $idempotencyKey): PurchaseOrder
    {
        return DB::transaction(function () use ($companyId, $user, $data, $idempotencyKey): PurchaseOrder {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $hash = $this->hash($data);
            $existing = PurchaseOrder::query()->where('company_id', $companyId)->where('creation_idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->creation_idempotency_hash, $hash)) {
                    throw new ConflictHttpException('The idempotency key has already been used for a different purchase order.');
                }

                return $existing->load(['supplier', 'lines.expenseAccount', 'receipts.lines']);
            }
            $this->supplier($companyId, (string) $data['supplier_id']);
            $calculation = $this->calculationService->calculate($data['lines']);
            $sequence = (int) PurchaseOrder::query()->where('company_id', $companyId)->max('sequence') + 1;
            $order = PurchaseOrder::query()->create([
                ...$this->header($data), ...$this->orderTotals($calculation['totals']), 'company_id' => $companyId,
                'sequence' => $sequence, 'number' => sprintf('PO-%s-%04d', date('Y', strtotime($data['order_date'])), $sequence),
                'status' => PurchaseOrderStatus::Draft, 'creation_idempotency_key' => $idempotencyKey,
                'creation_idempotency_hash' => $hash, 'created_by' => $user->id,
            ]);
            $order->lines()->createMany($calculation['lines']);

            return $order->load(['supplier', 'lines.expenseAccount', 'receipts.lines']);
        });
    }

    /** @param array<string,mixed> $data */
    public function update(string $companyId, User $user, PurchaseOrder $order, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($companyId, $user, $order, $data): PurchaseOrder {
            $order = PurchaseOrder::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($order->id);
            if (! $order->status->isEditable()) {
                throw ValidationException::withMessages(['purchase_order' => 'Only draft or rejected purchase orders can be edited.']);
            }
            $this->supplier($companyId, (string) $data['supplier_id']);
            $calculation = $this->calculationService->calculate($data['lines']);
            $order->update([
                ...$this->header($data), ...$this->orderTotals($calculation['totals']), 'status' => PurchaseOrderStatus::Draft,
                'updated_by' => $user->id, 'rejected_by' => null, 'rejected_at' => null, 'approval_note' => null,
            ]);
            $order->lines()->delete();
            $order->lines()->createMany($calculation['lines']);

            return $order->load(['supplier', 'lines.expenseAccount', 'receipts.lines']);
        });
    }

    public function submit(string $companyId, User $user, PurchaseOrder $order, ?string $note): PurchaseOrder
    {
        return $this->transition($companyId, $order, function (PurchaseOrder $locked) use ($user, $note): void {
            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages(['purchase_order' => 'Only a draft or rejected purchase order can be submitted.']);
            }
            $locked->update(['status' => PurchaseOrderStatus::PendingApproval, 'submitted_by' => $user->id, 'submitted_at' => now(), 'approval_note' => $note]);
        });
    }

    public function approve(string $companyId, User $user, PurchaseOrder $order, ?string $note): PurchaseOrder
    {
        return $this->transition($companyId, $order, function (PurchaseOrder $locked) use ($user, $note): void {
            if ($locked->status !== PurchaseOrderStatus::PendingApproval) {
                throw ValidationException::withMessages(['purchase_order' => 'Only a pending purchase order can be approved.']);
            }
            $locked->update(['status' => PurchaseOrderStatus::Approved, 'approved_by' => $user->id, 'approved_at' => now(), 'approval_note' => $note]);
        });
    }

    public function reject(string $companyId, User $user, PurchaseOrder $order, string $note): PurchaseOrder
    {
        return $this->transition($companyId, $order, function (PurchaseOrder $locked) use ($user, $note): void {
            if ($locked->status !== PurchaseOrderStatus::PendingApproval) {
                throw ValidationException::withMessages(['purchase_order' => 'Only a pending purchase order can be rejected.']);
            }
            $locked->update(['status' => PurchaseOrderStatus::Rejected, 'rejected_by' => $user->id, 'rejected_at' => now(), 'approval_note' => $note]);
        });
    }

    public function cancel(string $companyId, User $user, PurchaseOrder $order, string $reason): PurchaseOrder
    {
        return $this->transition($companyId, $order, function (PurchaseOrder $locked) use ($user, $reason): void {
            if (in_array($locked->status, [PurchaseOrderStatus::Received, PurchaseOrderStatus::Cancelled], true) || $locked->receipts()->exists() || $locked->bills()->exists()) {
                throw ValidationException::withMessages(['purchase_order' => 'A received, billed, or already cancelled purchase order cannot be cancelled.']);
            }
            $locked->update(['status' => PurchaseOrderStatus::Cancelled, 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
        });
    }

    /** @param callable(PurchaseOrder):void $change */
    private function transition(string $companyId, PurchaseOrder $order, callable $change): PurchaseOrder
    {
        return DB::transaction(function () use ($companyId, $order, $change): PurchaseOrder {
            $locked = PurchaseOrder::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($order->id);
            $change($locked);

            return $locked->load(['supplier', 'lines.expenseAccount', 'receipts.lines']);
        });
    }

    private function supplier(string $companyId, string $supplierId): Supplier
    {
        return Supplier::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($supplierId);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function header(array $data): array
    {
        return ['supplier_id' => $data['supplier_id'], 'order_date' => $data['order_date'], 'expected_delivery_date' => $data['expected_delivery_date'] ?? null, 'currency' => $data['currency'], 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null];
    }

    /** @param array<string,int> $totals @return array<string,int> */
    private function orderTotals(array $totals): array
    {
        return ['subtotal' => $totals['subtotal'], 'discount' => $totals['discount'], 'taxable_amount' => $totals['taxable_amount'], 'tax' => $totals['tax'], 'total' => $totals['gross_total']];
    }

    /** @param array<string,mixed> $data */
    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
