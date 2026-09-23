<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\StoreSupplierRequest;
use App\Http\Requests\Api\V1\Accounting\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Company;
use App\Models\Supplier;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'suppliers.view'), 403);
        $query = Supplier::query()->where('company_id', $this->companyId($request));
        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(fn (Builder $builder) => $builder->where('name', 'like', $search)->orWhere('code', 'like', $search)->orWhere('ntn', 'like', $search));
        }
        if ($request->filled('status') && $request->string('status')->toString() !== 'all') {
            $query->where('is_active', $request->string('status')->toString() === 'active');
        }

        return SupplierResource::collection($query->withSum(['bills as outstanding' => fn (Builder $bills) => $bills->whereNotNull('journal_id')->whereIn('status', ['unpaid', 'partial'])], 'balance_due')->orderBy('name')->get());
    }

    public function store(StoreSupplierRequest $request): SupplierResource
    {
        $supplier = DB::transaction(function () use ($request): Supplier {
            Company::query()->lockForUpdate()->findOrFail($this->companyId($request));
            $sequence = (int) Supplier::query()->where('company_id', $this->companyId($request))->max('sequence') + 1;

            return Supplier::query()->create([...$request->validated(), 'company_id' => $this->companyId($request), 'sequence' => $sequence, 'code' => $request->validated('code') ?? sprintf('SUP-%04d', $sequence), 'created_by' => $request->user()->id]);
        });
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'create', 'procurement', $supplier, null, $supplier->toArray());

        return new SupplierResource($supplier->load(['defaultExpenseAccount', 'defaultPayableAccount']));
    }

    public function show(Request $request, string $supplier): SupplierResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'suppliers.view'), 403);

        return new SupplierResource($this->query($request)->with(['defaultExpenseAccount', 'defaultPayableAccount'])->findOrFail($supplier));
    }

    public function update(UpdateSupplierRequest $request, string $supplier): SupplierResource
    {
        $model = $this->query($request)->findOrFail($supplier);
        $old = $model->toArray();
        $model->update([...$request->validated(), 'updated_by' => $request->user()->id]);
        $action = $old['is_active'] && ! $model->is_active ? 'deactivate' : 'update';
        $this->auditService->record($request, $request->user(), $this->companyId($request), $action, 'procurement', $model, $old, $model->toArray());

        return new SupplierResource($model->load(['defaultExpenseAccount', 'defaultPayableAccount']));
    }

    public function destroy(Request $request, string $supplier): mixed
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'suppliers.manage'), 403);
        $model = $this->query($request)->findOrFail($supplier);
        if ($model->purchaseOrders()->exists() || $model->bills()->exists() || $model->payments()->exists()) {
            throw ValidationException::withMessages(['supplier' => 'A supplier with procurement or financial history cannot be deleted. Deactivate it instead.']);
        }
        $old = $model->toArray();
        $model->delete();
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'delete', 'procurement', $model, $old, null);

        return response()->noContent();
    }

    private function query(Request $request): Builder
    {
        return Supplier::query()->where('company_id', $this->companyId($request));
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
