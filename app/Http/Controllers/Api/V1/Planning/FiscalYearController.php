<?php

namespace App\Http\Controllers\Api\V1\Planning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Planning\StoreFiscalYearRequest;
use App\Http\Resources\FiscalYearResource;
use App\Models\FiscalYear;
use App\Services\AuditService;
use App\Services\Planning\PlanningService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FiscalYearController extends Controller
{
    public function __construct(private readonly PlanningService $planning, private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = $this->companyId($request);
        abort_unless($request->user()->hasCompanyPermission($companyId, 'budget.view') || $request->user()->hasCompanyPermission($companyId, 'accounting.close.view'), 403);

        return FiscalYearResource::collection(FiscalYear::query()->where('company_id', $companyId)->with('periods')->orderByDesc('start_date')->get());
    }

    public function store(StoreFiscalYearRequest $request): FiscalYearResource
    {
        $year = $this->planning->createFiscalYear($this->companyId($request), $request->user(), $request->validated());
        $this->audit->record($request, $request->user(), $this->companyId($request), 'create', 'planning', $year, null, $year->toArray());

        return new FiscalYearResource($year);
    }

    public function show(Request $request, string $fiscalYear): FiscalYearResource
    {
        $companyId = $this->companyId($request);
        abort_unless($request->user()->hasCompanyPermission($companyId, 'budget.view') || $request->user()->hasCompanyPermission($companyId, 'accounting.close.view'), 403);

        return new FiscalYearResource(FiscalYear::query()->where('company_id', $companyId)->with('periods')->findOrFail($fiscalYear));
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
