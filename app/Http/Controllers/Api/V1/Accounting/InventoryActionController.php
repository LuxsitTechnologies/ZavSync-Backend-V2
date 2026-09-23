<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\InventoryActionRequest;
use App\Http\Resources\InventoryTransactionResource;
use App\Models\InventoryTransaction;
use App\Models\Invoice;
use App\Models\PurchaseReceipt;
use App\Services\AuditService;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InventoryActionController extends Controller
{
    public function __construct(private readonly InventoryService $service, private readonly AuditService $auditService) {}

    public function transfer(InventoryActionRequest $request): InventoryTransactionResource
    {
        return $this->respond($request, $this->service->transfer($this->companyId($request), $request->user(), $request->validated(), $this->idempotencyKey($request)), 'transfer');
    }

    public function adjustment(InventoryActionRequest $request): InventoryTransactionResource
    {
        return $this->respond($request, $this->service->adjust($this->companyId($request), $request->user(), $request->validated(), $this->idempotencyKey($request)), 'adjust');
    }

    public function customerReturn(InventoryActionRequest $request, string $invoice): InventoryTransactionResource
    {
        $model = Invoice::query()->where('company_id', $this->companyId($request))->findOrFail($invoice);

        return $this->respond($request, $this->service->customerReturn($this->companyId($request), $request->user(), $model, $request->validated(), $this->idempotencyKey($request)), 'customer_return');
    }

    public function supplierReturn(InventoryActionRequest $request, string $receipt): InventoryTransactionResource
    {
        $model = PurchaseReceipt::query()->where('company_id', $this->companyId($request))->findOrFail($receipt);

        return $this->respond($request, $this->service->supplierReturn($this->companyId($request), $request->user(), $model, $request->validated(), $this->idempotencyKey($request)), 'supplier_return');
    }

    public function costAdjustment(InventoryActionRequest $request): InventoryTransactionResource
    {
        return $this->respond($request, $this->service->costAdjustment($this->companyId($request), $request->user(), $request->validated(), $this->idempotencyKey($request)), 'cost_adjustment');
    }

    private function respond(Request $request, InventoryTransaction $transaction, string $action): InventoryTransactionResource
    {
        if ($transaction->wasRecentlyCreated) {
            $this->auditService->record($request, $request->user(), $this->companyId($request), $action, 'inventory', $transaction, null, $transaction->withoutRelations()->toArray());
        }

        return new InventoryTransactionResource($transaction);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || trim($key) === '' || mb_strlen($key) > 120) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid Idempotency-Key header is required.']);
        }

        return $key;
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
