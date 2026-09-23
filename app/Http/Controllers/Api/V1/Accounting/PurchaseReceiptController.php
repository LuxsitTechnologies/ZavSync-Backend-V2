<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\StorePurchaseReceiptRequest;
use App\Http\Resources\PurchaseReceiptResource;
use App\Models\InventoryTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Services\Accounting\PurchaseReceiptService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptController extends Controller
{
    public function __construct(private readonly PurchaseReceiptService $service, private readonly AuditService $auditService) {}

    public function index(Request $request, string $order): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'purchase_orders.view'), 403);
        PurchaseOrder::query()->where('company_id', $this->companyId($request))->findOrFail($order);

        return PurchaseReceiptResource::collection(PurchaseReceipt::query()->where('company_id', $this->companyId($request))->where('purchase_order_id', $order)->with(['supplier', 'purchaseOrder', 'warehouse', 'inventoryTransaction', 'lines.purchaseOrderLine'])->orderByDesc('receipt_date')->get());
    }

    public function store(StorePurchaseReceiptRequest $request, string $order): PurchaseReceiptResource
    {
        $model = PurchaseOrder::query()->where('company_id', $this->companyId($request))->findOrFail($order);
        $receipt = $this->service->receive($this->companyId($request), $request->user(), $model, $request->validated(), $this->idempotencyKey($request));
        if ($receipt->wasRecentlyCreated) {
            $this->auditService->record($request, $request->user(), $this->companyId($request), 'receive', 'procurement', $receipt, null, $receipt->withoutRelations()->toArray());
            $inventory = InventoryTransaction::query()->where('company_id', $this->companyId($request))->where('source_type', 'purchase_receipt')->where('source_id', $receipt->id)->first();
            if ($inventory !== null) {
                $this->auditService->record($request, $request->user(), $this->companyId($request), 'receipt_to_stock', 'inventory', $inventory, null, $inventory->toArray());
            }
        }

        return new PurchaseReceiptResource($receipt);
    }

    public function show(Request $request, string $receipt): PurchaseReceiptResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'purchase_orders.view'), 403);

        return new PurchaseReceiptResource(PurchaseReceipt::query()->where('company_id', $this->companyId($request))->with(['supplier', 'purchaseOrder', 'warehouse', 'inventoryTransaction', 'lines.purchaseOrderLine'])->findOrFail($receipt));
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
