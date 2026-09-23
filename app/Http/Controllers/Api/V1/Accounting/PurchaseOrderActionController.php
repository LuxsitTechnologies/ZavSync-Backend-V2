<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\StoreSupplierBillRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Http\Resources\SupplierBillResource;
use App\Models\PurchaseOrder;
use App\Services\Accounting\PurchaseOrderService;
use App\Services\Accounting\SupplierBillService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PurchaseOrderActionController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $orderService, private readonly SupplierBillService $billService, private readonly AuditService $auditService) {}

    public function submit(Request $request, string $order): PurchaseOrderResource
    {
        return $this->transition($request, $order, 'purchase_orders.update', 'submit', fn (PurchaseOrder $model) => $this->orderService->submit($this->companyId($request), $request->user(), $model, $this->note($request)));
    }

    public function approve(Request $request, string $order): PurchaseOrderResource
    {
        return $this->transition($request, $order, 'purchase_orders.approve', 'approve', fn (PurchaseOrder $model) => $this->orderService->approve($this->companyId($request), $request->user(), $model, $this->note($request)));
    }

    public function reject(Request $request, string $order): PurchaseOrderResource
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        return $this->transition($request, $order, 'purchase_orders.approve', 'reject', fn (PurchaseOrder $model) => $this->orderService->reject($this->companyId($request), $request->user(), $model, $data['note']));
    }

    public function cancel(Request $request, string $order): PurchaseOrderResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->transition($request, $order, 'purchase_orders.cancel', 'cancel', fn (PurchaseOrder $model) => $this->orderService->cancel($this->companyId($request), $request->user(), $model, $data['reason']));
    }

    public function convertToBill(StoreSupplierBillRequest $request, string $order): SupplierBillResource
    {
        $model = PurchaseOrder::query()->where('company_id', $this->companyId($request))->findOrFail($order);
        if ((string) $request->validated('purchase_order_id') !== $model->id) {
            throw ValidationException::withMessages(['purchase_order_id' => 'The bill must reference the purchase order in the URL.']);
        }
        $bill = $this->billService->create($this->companyId($request), $request->user(), $request->validated(), $this->idempotencyKey($request));
        if ($bill->wasRecentlyCreated) {
            $this->auditService->record($request, $request->user(), $this->companyId($request), 'convert_to_bill', 'procurement', $bill, null, $bill->withoutRelations()->toArray());
        }

        return new SupplierBillResource($bill);
    }

    /** @param callable(PurchaseOrder):PurchaseOrder $callback */
    private function transition(Request $request, string $order, string $permission, string $action, callable $callback): PurchaseOrderResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), $permission), 403);
        $model = PurchaseOrder::query()->where('company_id', $this->companyId($request))->findOrFail($order);
        $old = $model->toArray();
        $updated = $callback($model);
        $this->auditService->record($request, $request->user(), $this->companyId($request), $action, 'procurement', $updated, $old, $updated->withoutRelations()->toArray());

        return new PurchaseOrderResource($updated);
    }

    private function note(Request $request): ?string
    {
        return $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || trim($key) === '' || mb_strlen($key) > 100) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid Idempotency-Key header is required.']);
        }

        return $key;
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
