<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\StoreWarehouseRequest;
use App\Http\Requests\Api\V1\Accounting\UpdateWarehouseRequest;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'inventory.view'), 403);
        $warehouses = $this->query($request)->withSum('movements as quantity_in_total', 'quantity_in_milli')->withSum('movements as quantity_out_total', 'quantity_out_milli')->orderByDesc('is_default')->orderBy('name')->get()->each(fn (Warehouse $warehouse) => $warehouse->setAttribute('quantity_on_hand_milli', (int) $warehouse->quantity_in_total - (int) $warehouse->quantity_out_total));

        return WarehouseResource::collection($warehouses);
    }

    public function store(StoreWarehouseRequest $request): WarehouseResource
    {
        $warehouse = DB::transaction(function () use ($request): Warehouse {
            if ($request->validated('is_default')) {
                $this->query($request)->update(['is_default' => false]);
            }

            return Warehouse::query()->create([...$request->validated(), 'company_id' => $this->companyId($request), 'created_by' => $request->user()->id]);
        });
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'create', 'inventory', $warehouse, null, $warehouse->toArray());

        return new WarehouseResource($warehouse);
    }

    public function show(Request $request, string $warehouse): WarehouseResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'inventory.view'), 403);

        return new WarehouseResource($this->query($request)->findOrFail($warehouse));
    }

    public function update(UpdateWarehouseRequest $request, string $warehouse): WarehouseResource
    {
        $model = $this->query($request)->findOrFail($warehouse);
        $old = $model->toArray();
        DB::transaction(function () use ($request, $model): void {
            if ($request->validated('is_default')) {
                $this->query($request)->whereKeyNot($model->id)->update(['is_default' => false]);
            }
            $model->update([...$request->validated(), 'updated_by' => $request->user()->id]);
        });
        $action = $old['is_active'] && ! $model->is_active ? 'deactivate' : 'update';
        $this->auditService->record($request, $request->user(), $this->companyId($request), $action, 'inventory', $model, $old, $model->toArray());

        return new WarehouseResource($model);
    }

    public function destroy(Request $request, string $warehouse): mixed
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'inventory.manage'), 403);
        $model = $this->query($request)->findOrFail($warehouse);
        if ($model->movements()->exists()) {
            throw ValidationException::withMessages(['warehouse' => 'A warehouse with inventory history cannot be deleted. Deactivate it instead.']);
        }
        $old = $model->toArray();
        $model->delete();
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'delete', 'inventory', $model, $old, null);

        return response()->noContent();
    }

    private function query(Request $request): Builder
    {
        return Warehouse::query()->where('company_id', $this->companyId($request));
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
