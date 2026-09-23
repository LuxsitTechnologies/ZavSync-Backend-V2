<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\StoreAccountingPeriodRequest;
use App\Http\Requests\Api\V1\Accounting\UpdateAccountingPeriodRequest;
use App\Http\Resources\AccountingPeriodResource;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountingPeriodController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = (string) $request->attributes->get('company_id');
        abort_unless($request->user()->hasCompanyPermission($companyId, 'accounting.view'), 403);

        return AccountingPeriodResource::collection(AccountingPeriod::query()->where('company_id', $companyId)->orderByDesc('start_date')->get());
    }

    public function store(StoreAccountingPeriodRequest $request): AccountingPeriodResource
    {
        $companyId = (string) $request->attributes->get('company_id');
        $data = $request->validated();
        $fiscalYearId = FiscalYear::query()->where('company_id', $companyId)->whereDate('start_date', '<=', $data['start_date'])->whereDate('end_date', '>=', $data['end_date'])->value('id');
        $model = AccountingPeriod::query()->create([...$data, 'company_id' => $companyId, 'fiscal_year_id' => $fiscalYearId, 'status' => 'open']);
        $this->auditService->record($request, $request->user(), $companyId, 'create', 'accounting', $model, null, $model->toArray());

        return new AccountingPeriodResource($model);
    }

    public function update(UpdateAccountingPeriodRequest $request, string $period): AccountingPeriodResource
    {
        $companyId = (string) $request->attributes->get('company_id');
        $model = AccountingPeriod::query()->where('company_id', $companyId)->findOrFail($period);
        $oldValues = $model->toArray();
        $data = $request->validated();
        $startDate = $data['start_date'] ?? $model->start_date->format('Y-m-d');
        $endDate = $data['end_date'] ?? $model->end_date->format('Y-m-d');
        $data['fiscal_year_id'] = FiscalYear::query()->where('company_id', $companyId)->whereDate('start_date', '<=', $startDate)->whereDate('end_date', '>=', $endDate)->value('id');
        $model->update($data);
        $this->auditService->record($request, $request->user(), $companyId, 'update', 'accounting', $model, $oldValues, $model->fresh()->toArray());

        return new AccountingPeriodResource($model->fresh());
    }
}
