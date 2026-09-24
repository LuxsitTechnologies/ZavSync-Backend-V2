<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payroll\StorePayrollPaymentRequest;
use App\Http\Resources\PayrollPaymentResource;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use App\Models\PayrollPayment;
use App\Services\AuditService;
use App\Services\Payroll\PayrollPaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollPaymentController extends Controller
{
    public function __construct(private readonly PayrollPaymentService $payments, private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'payroll.view'), 403);

        return PayrollPaymentResource::collection(PayrollPayment::query()->where('company_id', $this->companyId($request))->with(['allocations.entry', 'financialAccount', 'journal'])->orderByDesc('payment_date')->get());
    }

    public function store(StorePayrollPaymentRequest $request, string $batch): PayrollPaymentResource
    {
        $model = PayrollBatch::query()->where('company_id', $this->companyId($request))->findOrFail($batch);
        $payment = $this->payments->pay($this->companyId($request), $request->user(), $model, $request->validated(), $this->idempotencyKey($request));
        $this->audit->record($request, $request->user(), $this->companyId($request), 'salary_payment', 'payroll', $payment, null, $payment->withoutRelations()->toArray());

        return new PayrollPaymentResource($payment);
    }

    public function payEntry(StorePayrollPaymentRequest $request, string $entry): PayrollPaymentResource
    {
        $entryModel = PayrollEntry::query()->where('company_id', $this->companyId($request))->findOrFail($entry);
        $data = $request->validated();
        $data['allocations'] = [['payroll_entry_id' => $entryModel->id, 'amount' => $data['amount']]];
        $batch = PayrollBatch::query()->where('company_id', $this->companyId($request))->findOrFail($entryModel->payroll_batch_id);
        $payment = $this->payments->pay($this->companyId($request), $request->user(), $batch, $data, $this->idempotencyKey($request));
        $this->audit->record($request, $request->user(), $this->companyId($request), 'employee_salary_payment', 'payroll', $payment, null, $payment->withoutRelations()->toArray());

        return new PayrollPaymentResource($payment);
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
