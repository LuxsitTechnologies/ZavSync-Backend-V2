<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\InventoryReportRequest;
use App\Services\Inventory\InventoryReportingService;
use Illuminate\Http\JsonResponse;

class InventoryReportController extends Controller
{
    public function __construct(private readonly InventoryReportingService $service) {}

    public function ledger(InventoryReportRequest $request): JsonResponse
    {
        return response()->json($this->service->ledger($this->companyId($request), $request->validated()));
    }

    public function valuation(InventoryReportRequest $request): JsonResponse
    {
        return response()->json($this->service->valuation($this->companyId($request), $request->validated()));
    }

    public function layers(InventoryReportRequest $request): JsonResponse
    {
        $filters = $request->validated();
        if ($request->route('item') !== null) {
            $filters['item_id'] = (string) $request->route('item');
        }

        return response()->json($this->service->layers($this->companyId($request), $filters));
    }

    public function lowStock(InventoryReportRequest $request): JsonResponse
    {
        return response()->json($this->service->lowStock($this->companyId($request)));
    }

    public function reconciliation(InventoryReportRequest $request): JsonResponse
    {
        return response()->json($this->service->reconciliation($this->companyId($request), $request->validated()));
    }

    public function cogs(InventoryReportRequest $request): JsonResponse
    {
        return response()->json($this->service->cogs($this->companyId($request), $request->validated('item_id')));
    }

    private function companyId(InventoryReportRequest $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
