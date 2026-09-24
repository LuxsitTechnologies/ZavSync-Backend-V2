<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payroll\ReversePayrollBatchRequest;
use App\Http\Requests\Api\V1\Payroll\StorePayrollBatchRequest;
use App\Http\Resources\PayrollBatchResource;
use App\Models\PayrollBatch;
use App\Services\AuditService;
use App\Services\Payroll\PayrollCalculationService;
use App\Services\Payroll\PayrollPostingService;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollBatchController extends Controller
{
    public function __construct(private readonly PayrollService $payroll, private readonly PayrollCalculationService $calculation, private readonly PayrollPostingService $posting, private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);
        $query = PayrollBatch::query()->where('company_id', $this->companyId($request))->with('period');
        if ($request->filled('status')) {
            $query->where('status', mb_strtoupper($request->string('status')->toString()));
        }

        return PayrollBatchResource::collection($query->orderByDesc('accounting_date')->get());
    }

    public function store(StorePayrollBatchRequest $request): JsonResponse
    {
        $batch = $this->payroll->createBatch($this->companyId($request), $request->user(), $request->validated());
        $this->record($request, 'create_batch', $batch);

        return (new PayrollBatchResource($batch))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $batch): PayrollBatchResource
    {
        $this->authorizeView($request);

        return new PayrollBatchResource($this->batch($request, $batch)->load(['period', 'entries.lines', 'entries.adjustments', 'entries.paymentAllocations', 'journal.lines.account']));
    }

    public function calculate(Request $request, string $batch): PayrollBatchResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'payroll.calculate'), 403);
        $model = $this->calculation->calculate($this->companyId($request), $this->batch($request, $batch));
        $this->record($request, 'calculate', $model);

        return new PayrollBatchResource($model);
    }

    public function recalculate(Request $request, string $batch): PayrollBatchResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'payroll.calculate'), 403);
        $model = $this->calculation->calculate($this->companyId($request), $this->batch($request, $batch));
        $this->record($request, 'recalculate', $model);

        return new PayrollBatchResource($model);
    }

    public function review(Request $request, string $batch): PayrollBatchResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'payroll.review'), 403);
        $model = $this->payroll->review($this->companyId($request), $request->user(), $this->batch($request, $batch));
        $this->record($request, 'review', $model);

        return new PayrollBatchResource($model);
    }

    public function approve(Request $request, string $batch): PayrollBatchResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'payroll.approve'), 403);
        $model = $this->payroll->approve($this->companyId($request), $request->user(), $this->batch($request, $batch));
        $this->record($request, 'approve', $model);

        return new PayrollBatchResource($model);
    }

    public function post(Request $request, string $batch): PayrollBatchResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'payroll.post'), 403);
        $model = $this->posting->post($this->companyId($request), $request->user(), $this->batch($request, $batch), $this->idempotencyKey($request));
        $this->record($request, 'post', $model);

        return new PayrollBatchResource($model);
    }

    public function reverse(ReversePayrollBatchRequest $request, string $batch): PayrollBatchResource
    {
        $data = $request->validated();
        $model = $this->posting->reverse($this->companyId($request), $request->user(), $this->batch($request, $batch), $data['posting_date'], $data['reason'], $this->idempotencyKey($request));
        $this->record($request, 'reverse', $model);

        return new PayrollBatchResource($model);
    }

    public function preview(Request $request, string $batch): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json(['data' => $this->posting->preview($this->companyId($request), $this->batch($request, $batch))]);
    }

    private function batch(Request $request, string $id): PayrollBatch
    {
        return PayrollBatch::query()->where('company_id', $this->companyId($request))->findOrFail($id);
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

    private function record(Request $request, string $action, PayrollBatch $batch): void
    {
        $this->audit->record($request, $request->user(), $this->companyId($request), $action, 'payroll', $batch, null, $batch->withoutRelations()->toArray());
    }
}
