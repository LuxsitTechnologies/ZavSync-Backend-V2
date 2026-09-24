<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payroll\StorePayrollAdjustmentRequest;
use App\Http\Resources\PayrollEntryResource;
use App\Models\PayrollBatch;
use App\Models\PayrollComponent;
use App\Models\PayrollEntry;
use App\Services\AuditService;
use App\Services\Payroll\PayrollReportingService;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollEntryController extends Controller
{
    public function __construct(private readonly PayrollService $payroll, private readonly PayrollReportingService $reports, private readonly AuditService $audit) {}

    public function index(Request $request, string $batch): AnonymousResourceCollection
    {
        $this->authorizeView($request);
        PayrollBatch::query()->where('company_id', $this->companyId($request))->findOrFail($batch);

        return PayrollEntryResource::collection(PayrollEntry::query()->where('company_id', $this->companyId($request))->where('payroll_batch_id', $batch)->with(['lines', 'adjustments', 'paymentAllocations'])->orderBy('employee_code')->get());
    }

    public function show(Request $request, string $entry): PayrollEntryResource
    {
        $this->authorizeView($request);

        return new PayrollEntryResource($this->entry($request, $entry)->load(['lines', 'adjustments', 'paymentAllocations']));
    }

    public function adjust(StorePayrollAdjustmentRequest $request, string $entry): JsonResponse
    {
        $component = PayrollComponent::query()->where('company_id', $this->companyId($request))->findOrFail($request->validated('payroll_component_id'));
        $adjustment = $this->payroll->addAdjustment($this->companyId($request), $request->user(), $this->entry($request, $entry), $component, $request->integer('amount'), $request->string('reason')->toString());
        $this->audit->record($request, $request->user(), $this->companyId($request), 'manual_adjustment', 'payroll', $adjustment, null, $adjustment->toArray());

        return response()->json(['data' => $adjustment], 201);
    }

    public function payslip(Request $request, string $entry): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json(['data' => $this->reports->payslip($this->companyId($request), $this->entry($request, $entry))]);
    }

    private function entry(Request $request, string $id): PayrollEntry
    {
        return PayrollEntry::query()->where('company_id', $this->companyId($request))->findOrFail($id);
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
