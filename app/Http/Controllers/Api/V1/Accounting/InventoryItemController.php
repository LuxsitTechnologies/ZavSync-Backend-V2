<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\StoreInventoryItemRequest;
use App\Http\Requests\Api\V1\Accounting\UpdateInventoryItemRequest;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class InventoryItemController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'inventory.view'), 403);
        $query = $this->query($request)
            ->withSum('movements as quantity_in_total', 'quantity_in_milli')
            ->withSum('movements as quantity_out_total', 'quantity_out_milli')
            ->withSum('layers as inventory_value', 'remaining_value');
        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(fn (Builder $builder) => $builder->where('sku', 'like', $search)->orWhere('name', 'like', $search)->orWhere('category', 'like', $search));
        }
        if ($request->filled('type') && $request->string('type')->toString() !== 'all') {
            $query->where('type', $request->string('type'));
        }
        if ($request->filled('status') && $request->string('status')->toString() !== 'all') {
            $query->where('is_active', $request->string('status')->toString() === 'active');
        }
        $items = $query->orderBy('name')->get()->each(fn (InventoryItem $item) => $item->setAttribute('quantity_on_hand_milli', (int) $item->quantity_in_total - (int) $item->quantity_out_total));

        return InventoryItemResource::collection($items);
    }

    public function store(StoreInventoryItemRequest $request): InventoryItemResource
    {
        $item = InventoryItem::query()->create([...$request->validated(), 'company_id' => $this->companyId($request), 'created_by' => $request->user()->id]);
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'create', 'inventory', $item, null, $item->toArray());

        return new InventoryItemResource($item);
    }

    public function show(Request $request, string $item): InventoryItemResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'inventory.view'), 403);

        return new InventoryItemResource($this->query($request)->findOrFail($item));
    }

    public function update(UpdateInventoryItemRequest $request, string $item): InventoryItemResource
    {
        $model = $this->query($request)->findOrFail($item);
        $old = $model->toArray();
        $model->update([...$request->validated(), 'updated_by' => $request->user()->id]);
        $action = $old['is_active'] && ! $model->is_active ? 'deactivate' : 'update';
        $this->auditService->record($request, $request->user(), $this->companyId($request), $action, 'inventory', $model, $old, $model->toArray());

        return new InventoryItemResource($model);
    }

    public function destroy(Request $request, string $item): mixed
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'inventory.manage'), 403);
        $model = $this->query($request)->findOrFail($item);
        if ($model->movements()->exists()) {
            throw ValidationException::withMessages(['item' => 'An item with inventory history cannot be deleted. Deactivate it instead.']);
        }
        $old = $model->toArray();
        $model->delete();
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'delete', 'inventory', $model, $old, null);

        return response()->noContent();
    }

    private function query(Request $request): Builder
    {
        return InventoryItem::query()->where('company_id', $this->companyId($request));
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
