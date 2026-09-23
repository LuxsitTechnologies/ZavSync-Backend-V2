<?php

namespace App\Http\Controllers\Api\V1\Banking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Banking\StoreGatewaySettlementRequest;
use App\Http\Resources\GatewaySettlementResource;
use App\Models\GatewaySettlement;
use App\Services\AuditService;
use App\Services\Banking\BankingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GatewaySettlementController extends Controller
{
    public function __construct(private readonly BankingService $service, private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.view'), 403);

        return GatewaySettlementResource::collection(GatewaySettlement::query()->where('company_id', $this->companyId($request))->with(['destinationAccount', 'allocations'])->latest('settlement_date')->get());
    }

    public function store(StoreGatewaySettlementRequest $request): GatewaySettlementResource
    {
        $settlement = $this->service->gatewaySettlement($this->companyId($request), $request->user(), $request->validated(), $this->idempotencyKey($request));
        $this->auditService->record($request, $request->user(), $this->companyId($request), $settlement->status === 'posted' ? 'create_and_post_settlement' : 'create_settlement', 'banking', $settlement, null, $settlement->withoutRelations()->toArray());

        return new GatewaySettlementResource($settlement);
    }

    public function show(Request $request, string $settlement): GatewaySettlementResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.view'), 403);

        return new GatewaySettlementResource($this->model($request, $settlement)->load(['destinationAccount', 'allocations', 'journal.lines.account']));
    }

    public function post(Request $request, string $settlement): GatewaySettlementResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.settlements'), 403);
        $model = $this->model($request, $settlement);
        $old = $model->toArray();
        $model = $this->service->postSettlement($this->companyId($request), $request->user(), $model);
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'post_settlement', 'banking', $model, $old, $model->withoutRelations()->toArray());

        return new GatewaySettlementResource($model);
    }

    private function model(Request $request, string $id): GatewaySettlement
    {
        return GatewaySettlement::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        abort_if(! is_string($key) || trim($key) === '', 422, 'Idempotency-Key header is required.');

        return trim($key);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
