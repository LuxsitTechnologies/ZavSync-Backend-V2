<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Enums\SupplierBillStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\PayableReportRequest;
use App\Http\Requests\Api\V1\Accounting\StoreSupplierPaymentRequest;
use App\Http\Resources\SupplierBillResource;
use App\Http\Resources\SupplierPaymentResource;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\SupplierPaymentService;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PayableController extends Controller
{
    public function __construct(private readonly SupplierPaymentService $paymentService, private readonly AccountsPayableService $payableService, private readonly AuditService $auditService) {}

    public function suppliers(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'payables.view'), 403);

        return SupplierResource::collection(Supplier::query()->where('company_id', $this->companyId($request))->withSum(['bills as outstanding' => fn (Builder $bills) => $bills->whereNotNull('journal_id')->whereIn('status', [SupplierBillStatus::Unpaid->value, SupplierBillStatus::PartiallyPaid->value])], 'balance_due')->orderBy('name')->get());
    }

    public function bills(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'payables.view'), 403);
        $query = SupplierBill::query()->where('company_id', $this->companyId($request))->whereNotNull('journal_id')->where('status', '!=', SupplierBillStatus::Void->value)->with(['supplier', 'lines.expenseAccount']);
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->string('supplier_id')->toString());
        }
        $status = $request->string('status')->toString();
        if ($status === 'overdue' || $request->boolean('overdue_only')) {
            $query->whereIn('status', [SupplierBillStatus::Unpaid->value, SupplierBillStatus::PartiallyPaid->value])->where('balance_due', '>', 0)->whereDate('due_date', '<', today());
        } elseif ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        return SupplierBillResource::collection($query->orderByDesc('posting_date')->get());
    }

    public function payments(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'payables.view'), 403);
        $query = SupplierPayment::query()->where('company_id', $this->companyId($request))->with(['supplier', 'allocations.bill']);
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->string('supplier_id')->toString());
        }

        return SupplierPaymentResource::collection($query->orderByDesc('posting_date')->get());
    }

    public function recordPayment(StoreSupplierPaymentRequest $request, string $bill): SupplierBillResource
    {
        $companyId = $this->companyId($request);
        $idempotencyKey = $this->idempotencyKey($request);
        $model = SupplierBill::query()->where('company_id', $companyId)->findOrFail($bill);
        $alreadyExists = SupplierPayment::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->exists();
        $updated = $this->paymentService->record($companyId, $request->user(), $model, $request->validated(), $idempotencyKey);
        if (! $alreadyExists) {
            $payment = SupplierPayment::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->firstOrFail();
            $this->auditService->record($request, $request->user(), $companyId, 'create_and_post', 'accounts_payable', $payment, null, $payment->toArray());
        }

        return new SupplierBillResource($updated);
    }

    public function aging(PayableReportRequest $request): array
    {
        return $this->payableService->aging($this->companyId($request), $request->validated('supplier_id'), $request->validated('as_of') ?? today()->toDateString());
    }

    public function statement(PayableReportRequest $request, string $supplier): array
    {
        $model = Supplier::query()->where('company_id', $this->companyId($request))->findOrFail($supplier);

        return $this->payableService->statement($this->companyId($request), $model, $request->validated('from') ?? '1900-01-01', $request->validated('to') ?? today()->toDateString());
    }

    public function ledger(PayableReportRequest $request, string $supplier): array
    {
        return $this->statement($request, $supplier);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || trim($key) === '' || mb_strlen($key) > 100) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid Idempotency-Key header is required.']);
        }

        return $key;
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
