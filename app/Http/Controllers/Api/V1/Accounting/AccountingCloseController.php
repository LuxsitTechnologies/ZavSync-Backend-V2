<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\CloseFiscalYearRequest;
use App\Http\Requests\Api\V1\Accounting\ClosePeriodRequest;
use App\Http\Requests\Api\V1\Accounting\ReopenFiscalYearRequest;
use App\Http\Requests\Api\V1\Accounting\ReopenPeriodRequest;
use App\Http\Resources\AccountingCloseRecordResource;
use App\Models\AccountingCloseRecord;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Services\Accounting\AccountingCloseService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountingCloseController extends Controller
{
    public function __construct(private readonly AccountingCloseService $closeService, private readonly AuditService $audit) {}

    public function readiness(Request $request, string $period): JsonResponse
    {
        $this->authorizeView($request);
        $model = AccountingPeriod::query()->where('company_id', $this->companyId($request))->findOrFail($period);
        $result = $this->closeService->periodReadiness($this->companyId($request), $model);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'readiness', 'accounting_close', $model, null, $result);

        return response()->json($result);
    }

    public function closePeriod(ClosePeriodRequest $request, string $period): AccountingCloseRecordResource
    {
        $model = AccountingPeriod::query()->where('company_id', $this->companyId($request))->findOrFail($period);
        $record = $this->closeService->closePeriod($this->companyId($request), $request->user(), $model, $request->validated('idempotency_key'));
        $this->recordAudit($request, 'close', $record);

        return new AccountingCloseRecordResource($record);
    }

    public function reopenPeriod(ReopenPeriodRequest $request, string $period): AccountingCloseRecordResource
    {
        $model = AccountingPeriod::query()->where('company_id', $this->companyId($request))->findOrFail($period);
        $record = $this->closeService->reopenPeriod($this->companyId($request), $request->user(), $model, $request->validated('reason'));
        $this->recordAudit($request, 'reopen', $record);

        return new AccountingCloseRecordResource($record);
    }

    public function history(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);

        return AccountingCloseRecordResource::collection(AccountingCloseRecord::query()->where('company_id', $this->companyId($request))->latest('closed_at')->get());
    }

    public function yearEndPreview(Request $request, string $fiscalYear): JsonResponse
    {
        $this->authorizeView($request);
        $year = FiscalYear::query()->where('company_id', $this->companyId($request))->findOrFail($fiscalYear);
        $preview = $this->closeService->yearEndPreview($this->companyId($request), $year);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'preview', 'accounting_close', $year, null, $preview);

        return response()->json($preview);
    }

    public function closeFiscalYear(CloseFiscalYearRequest $request, string $fiscalYear): AccountingCloseRecordResource
    {
        $year = FiscalYear::query()->where('company_id', $this->companyId($request))->findOrFail($fiscalYear);
        $record = $this->closeService->closeFiscalYear($this->companyId($request), $request->user(), $year, $request->validated('idempotency_key'), $request->validated('confirmation'));
        $this->recordAudit($request, 'year_close', $record);

        return new AccountingCloseRecordResource($record);
    }

    public function reopenFiscalYear(ReopenFiscalYearRequest $request, string $fiscalYear): AccountingCloseRecordResource
    {
        $year = FiscalYear::query()->where('company_id', $this->companyId($request))->findOrFail($fiscalYear);
        $record = $this->closeService->reopenFiscalYear($this->companyId($request), $request->user(), $year, $request->validated('reason'));
        $this->recordAudit($request, 'year_reopen', $record);

        return new AccountingCloseRecordResource($record);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'accounting.close.view'), 403);
    }

    private function recordAudit(Request $request, string $action, AccountingCloseRecord $record): void
    {
        $this->audit->record($request, $request->user(), $this->companyId($request), $action, 'accounting_close', $record, null, $record->toArray());
    }
}
