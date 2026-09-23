<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\StoreAccountingPeriodRequest;
use App\Http\Requests\Api\V1\Accounting\UpdateAccountingPeriodRequest;
use App\Http\Resources\AccountingPeriodResource;
use App\Models\AccountingPeriod;
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
        $model = AccountingPeriod::query()->create([...$request->validated(), 'company_id' => $companyId, 'status' => 'open']);
        $this->auditService->record($request, $request->user(), $companyId, 'create', 'accounting', $model, null, $model->toArray());

        return new AccountingPeriodResource($model);
    }

    public function update(UpdateAccountingPeriodRequest $request, string $period): AccountingPeriodResource
    {
        $companyId = (string) $request->attributes->get('company_id');
        $model = AccountingPeriod::query()->where('company_id', $companyId)->findOrFail($period);
        $oldValues = $model->toArray();
        $data = $request->validated();
        if (array_key_exists('status', $data)) {
            $data['closed_by'] = $data['status'] === 'closed' ? $request->user()->id : null;
            $data['closed_at'] = $data['status'] === 'closed' ? now() : null;
        }
        $model->update($data);
        $action = array_key_exists('status', $data) ? ($data['status'] === 'closed' ? 'lock' : 'unlock') : 'update';
        $this->auditService->record($request, $request->user(), $companyId, $action, 'accounting', $model, $oldValues, $model->fresh()->toArray());

        return new AccountingPeriodResource($model->fresh());
    }
}
