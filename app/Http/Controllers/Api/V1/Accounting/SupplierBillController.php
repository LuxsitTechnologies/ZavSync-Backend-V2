<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Enums\SupplierBillStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\StoreSupplierBillRequest;
use App\Http\Requests\Api\V1\Accounting\UpdateSupplierBillRequest;
use App\Http\Resources\SupplierBillResource;
use App\Models\SupplierBill;
use App\Services\Accounting\SupplierBillService;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class SupplierBillController extends Controller
{
    public function __construct(private readonly SupplierBillService $service, private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'supplier_bills.view'), 403);
        $query = $this->query($request)->with(['supplier', 'lines.expenseAccount']);
        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(fn (Builder $builder) => $builder->where('bill_number', 'like', $search)->orWhere('supplier_invoice_number', 'like', $search)->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $search)));
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->string('supplier_id')->toString());
        }
        if ($request->filled('from')) {
            $query->whereDate('bill_date', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('bill_date', '<=', $request->date('to'));
        }
        if ($request->filled('due_within_days')) {
            $query->where('balance_due', '>', 0)->whereDate('due_date', '<=', today()->addDays($request->integer('due_within_days')));
        }
        $status = $request->string('status')->toString();
        if ($status === 'overdue' || $request->boolean('overdue_only')) {
            $query->whereIn('status', [SupplierBillStatus::Unpaid->value, SupplierBillStatus::PartiallyPaid->value])->where('balance_due', '>', 0)->whereDate('due_date', '<', today());
        } elseif ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        return SupplierBillResource::collection($query->orderByDesc('bill_date')->orderByDesc('sequence')->get());
    }

    public function store(StoreSupplierBillRequest $request): SupplierBillResource
    {
        $bill = $this->service->create($this->companyId($request), $request->user(), $request->validated(), $this->idempotencyKey($request));
        if ($bill->wasRecentlyCreated) {
            $this->auditService->record($request, $request->user(), $this->companyId($request), 'create', 'accounts_payable', $bill, null, $bill->withoutRelations()->toArray());
        }

        return new SupplierBillResource($bill);
    }

    public function show(Request $request, string $bill): SupplierBillResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'supplier_bills.view'), 403);

        return new SupplierBillResource($this->query($request)->with(['company', 'supplier', 'purchaseOrder', 'purchaseReceipt', 'lines.expenseAccount', 'journal', 'reversalJournal', 'allocations.payment'])->findOrFail($bill));
    }

    public function update(UpdateSupplierBillRequest $request, string $bill): SupplierBillResource
    {
        $model = $this->query($request)->findOrFail($bill);
        $old = $model->load('lines')->toArray();
        $updated = $this->service->update($this->companyId($request), $request->user(), $model, $request->validated());
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'update', 'accounts_payable', $updated, $old, $updated->withoutRelations()->toArray());

        return new SupplierBillResource($updated);
    }

    public function destroy(Request $request, string $bill): mixed
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'supplier_bills.manage'), 403);
        $model = $this->query($request)->findOrFail($bill);
        $old = $model->load('lines')->toArray();
        $this->service->deleteDraft($this->companyId($request), $model);
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'delete', 'accounts_payable', $model, $old, null);

        return response()->noContent();
    }

    private function query(Request $request): Builder
    {
        return SupplierBill::query()->where('company_id', $this->companyId($request));
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
