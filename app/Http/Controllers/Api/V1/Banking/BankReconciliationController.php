<?php

namespace App\Http\Controllers\Api\V1\Banking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Banking\StoreBankReconciliationRequest;
use App\Http\Resources\BankReconciliationResource;
use App\Models\BankReconciliation;
use App\Services\AuditService;
use App\Services\Banking\BankingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BankReconciliationController extends Controller
{
    public function __construct(private readonly BankingService $service, private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.view'), 403);

        return BankReconciliationResource::collection(BankReconciliation::query()->where('company_id', $this->companyId($request))->with('financialAccount')->latest('period_end')->get());
    }

    public function store(StoreBankReconciliationRequest $request): BankReconciliationResource
    {
        $model = $this->service->createReconciliation($this->companyId($request), $request->user(), $request->validated(), $this->idempotencyKey($request));
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'create_reconciliation', 'banking', $model, null, $model->withoutRelations()->toArray());

        return new BankReconciliationResource($model);
    }

    public function show(Request $request, string $reconciliation): BankReconciliationResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.view'), 403);

        return new BankReconciliationResource($this->model($request, $reconciliation)->load(['financialAccount', 'statementImport', 'matches.bankTransaction']));
    }

    public function complete(Request $request, string $reconciliation): BankReconciliationResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.reconcile'), 403);
        $model = $this->model($request, $reconciliation);
        $old = $model->toArray();
        $model = $this->service->completeReconciliation($this->companyId($request), $request->user(), $model);
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'complete_reconciliation', 'banking', $model, $old, $model->withoutRelations()->toArray());

        return new BankReconciliationResource($model);
    }

    public function reopen(Request $request, string $reconciliation): BankReconciliationResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.reconcile'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $model = $this->model($request, $reconciliation);
        $old = $model->toArray();
        $model = $this->service->reopenReconciliation($this->companyId($request), $request->user(), $model, $data['reason']);
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'reopen_reconciliation', 'banking', $model, $old, $model->withoutRelations()->toArray());

        return new BankReconciliationResource($model);
    }

    private function model(Request $request, string $id): BankReconciliation
    {
        return BankReconciliation::query()->where('company_id', $this->companyId($request))->findOrFail($id);
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
