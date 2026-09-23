<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\StorePurchaseOrderRequest;
use App\Http\Requests\Api\V1\Accounting\UpdatePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\Accounting\PurchaseOrderService;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service, private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'purchase_orders.view'), 403);
        $query = $this->query($request)->with(['supplier', 'lines']);
        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(fn (Builder $builder) => $builder->where('number', 'like', $search)->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $search)));
        }
        if ($request->filled('status') && $request->string('status')->toString() !== 'all') {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->string('supplier_id')->toString());
        }

        return PurchaseOrderResource::collection($query->orderByDesc('order_date')->orderByDesc('sequence')->get());
    }

    public function store(StorePurchaseOrderRequest $request): PurchaseOrderResource
    {
        $order = $this->service->create($this->companyId($request), $request->user(), $request->validated(), $this->idempotencyKey($request));
        if ($order->wasRecentlyCreated) {
            $this->auditService->record($request, $request->user(), $this->companyId($request), 'create', 'procurement', $order, null, $order->withoutRelations()->toArray());
        }

        return new PurchaseOrderResource($order);
    }

    public function show(Request $request, string $order): PurchaseOrderResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'purchase_orders.view'), 403);

        return new PurchaseOrderResource($this->query($request)->with(['supplier', 'lines.expenseAccount', 'receipts.lines', 'bills'])->findOrFail($order));
    }

    public function update(UpdatePurchaseOrderRequest $request, string $order): PurchaseOrderResource
    {
        $model = $this->query($request)->findOrFail($order);
        $old = $model->load('lines')->toArray();
        $updated = $this->service->update($this->companyId($request), $request->user(), $model, $request->validated());
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'update', 'procurement', $updated, $old, $updated->withoutRelations()->toArray());

        return new PurchaseOrderResource($updated);
    }

    public function destroy(Request $request, string $order): mixed
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'purchase_orders.update'), 403);
        $model = $this->query($request)->findOrFail($order);
        if (! $model->status->isEditable()) {
            throw ValidationException::withMessages(['purchase_order' => 'Only a draft or rejected purchase order can be deleted.']);
        }
        $old = $model->load('lines')->toArray();
        $model->delete();
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'delete', 'procurement', $model, $old, null);

        return response()->noContent();
    }

    private function query(Request $request): Builder
    {
        return PurchaseOrder::query()->where('company_id', $this->companyId($request));
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
