<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\ReceivableReportRequest;
use App\Http\Requests\Api\V1\Accounting\StoreCustomerPaymentRequest;
use App\Http\Resources\CustomerPaymentResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\InvoiceResource;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\CustomerPaymentService;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ReceivableController extends Controller
{
    public function __construct(private readonly CustomerPaymentService $paymentService, private readonly AccountsReceivableService $receivableService, private readonly AuditService $auditService) {}

    public function customers(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'accounting.view'), 403);
        $customers = Customer::query()->where('company_id', $this->companyId($request))->withSum(['invoices as outstanding' => fn (Builder $invoices) => $invoices->whereNotNull('journal_id')->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])], 'balance_due')->orderBy('name')->get();

        return CustomerResource::collection($customers);
    }

    public function invoices(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'accounting.view'), 403);
        $query = Invoice::query()->where('company_id', $this->companyId($request))->whereNotNull('journal_id')->where('status', '!=', InvoiceStatus::Void->value)->with('customer');
        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(fn (Builder $builder) => $builder->where('invoice_number', 'like', $search)->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $search)));
        }
        if ($request->filled('customer_id') && $request->string('customer_id')->toString() !== 'all') {
            $query->where('customer_id', $request->string('customer_id')->toString());
        }
        if ($request->filled('from')) {
            $query->whereDate('invoice_date', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('invoice_date', '<=', $request->date('to'));
        }
        $status = $request->string('status')->toString();
        if ($status === 'overdue' || $request->boolean('overdue_only')) {
            $query->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])->where('balance_due', '>', 0)->whereDate('due_date', '<', today());
        } elseif ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        return InvoiceResource::collection($query->orderByDesc('invoice_date')->get());
    }

    public function payments(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'accounting.view'), 403);
        $query = CustomerPayment::query()->where('company_id', $this->companyId($request))->with(['customer', 'invoice']);
        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->string('invoice_id')->toString());
        }

        return CustomerPaymentResource::collection($query->orderByDesc('payment_date')->get());
    }

    public function recordPayment(StoreCustomerPaymentRequest $request, string $invoice): InvoiceResource
    {
        $companyId = $this->companyId($request);
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid Idempotency-Key header is required.']);
        }
        $model = Invoice::query()->where('company_id', $companyId)->findOrFail($invoice);
        $alreadyExists = CustomerPayment::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->exists();
        $updated = $this->paymentService->record($companyId, $request->user(), $model, $request->validated(), $idempotencyKey);
        if (! $alreadyExists) {
            $payment = CustomerPayment::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->firstOrFail();
            $this->auditService->record($request, $request->user(), $companyId, 'create_and_post', 'accounts_receivable', $payment, null, $payment->toArray());
        }

        return new InvoiceResource($updated);
    }

    public function aging(ReceivableReportRequest $request): array
    {
        $asOf = $request->validated('as_of') ?? today()->toDateString();

        return $this->receivableService->aging($this->companyId($request), $request->validated('customer_id'), $asOf);
    }

    public function statement(ReceivableReportRequest $request, string $customer): array
    {
        $model = Customer::query()->where('company_id', $this->companyId($request))->findOrFail($customer);
        $from = $request->validated('from') ?? '1900-01-01';
        $to = $request->validated('to') ?? today()->toDateString();

        return $this->receivableService->statement($this->companyId($request), $model, $from, $to);
    }

    public function ledger(ReceivableReportRequest $request, string $customer): array
    {
        return $this->statement($request, $customer);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
