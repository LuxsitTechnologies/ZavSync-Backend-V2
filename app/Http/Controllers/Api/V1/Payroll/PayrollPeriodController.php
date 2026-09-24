<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payroll\StorePayrollPeriodRequest;
use App\Http\Resources\PayrollPeriodResource;
use App\Models\PayrollPeriod;
use App\Services\AuditService;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollPeriodController extends Controller
{
    public function __construct(private readonly PayrollService $payroll, private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);

        return PayrollPeriodResource::collection(PayrollPeriod::query()->where('company_id', $this->companyId($request))->orderByDesc('period_start')->get());
    }

    public function store(StorePayrollPeriodRequest $request): PayrollPeriodResource
    {
        $period = $this->payroll->createPeriod($this->companyId($request), $request->user(), $request->validated());
        $this->audit->record($request, $request->user(), $this->companyId($request), 'create_period', 'payroll', $period, null, $period->toArray());

        return new PayrollPeriodResource($period);
    }

    public function show(Request $request, string $period): PayrollPeriodResource
    {
        $this->authorizeView($request);

        return new PayrollPeriodResource(PayrollPeriod::query()->where('company_id', $this->companyId($request))->findOrFail($period));
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'payroll.view'), 403);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
