<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payroll\StorePayrollLiabilitySettlementRequest;
use App\Http\Resources\PayrollLiabilitySettlementResource;
use App\Models\PayrollLiabilitySettlement;
use App\Services\AuditService;
use App\Services\Payroll\PayrollPaymentService;
use App\Services\Payroll\PayrollReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollLiabilityController extends Controller
{
    public function __construct(private readonly PayrollPaymentService $payments, private readonly PayrollReportingService $reports, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json(['data' => $this->reports->liabilities($this->companyId($request), $request->query('payroll_batch_id'))]);
    }

    public function store(StorePayrollLiabilitySettlementRequest $request): PayrollLiabilitySettlementResource
    {
        $settlement = $this->payments->settle($this->companyId($request), $request->user(), $request->validated(), $this->idempotencyKey($request));
        $this->audit->record($request, $request->user(), $this->companyId($request), 'liability_settlement', 'payroll', $settlement, null, $settlement->withoutRelations()->toArray());

        return new PayrollLiabilitySettlementResource($settlement);
    }

    public function history(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $settlements = PayrollLiabilitySettlement::query()->where('company_id', $this->companyId($request))->with(['allocations.entryLine.entry', 'financialAccount', 'journal'])->orderByDesc('payment_date')->get();

        return response()->json(['data' => PayrollLiabilitySettlementResource::collection($settlements)]);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'payroll.view'), 403);
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
