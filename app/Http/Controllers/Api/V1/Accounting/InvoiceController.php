<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\StoreInvoiceRequest;
use App\Http\Requests\Api\V1\Accounting\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\Accounting\InvoiceService;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoiceService, private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'accounting.view'), 403);
        $query = $this->query($request)->with('customer');
        $this->applyFilters($query, $request);

        return InvoiceResource::collection($query->orderByDesc('invoice_date')->orderByDesc('sequence')->get());
    }

    public function store(StoreInvoiceRequest $request): InvoiceResource
    {
        $idempotencyKey = $this->idempotencyKey($request);
        $invoice = $this->invoiceService->create($this->companyId($request), $request->user(), $request->validated(), $idempotencyKey);
        if ($invoice->wasRecentlyCreated) {
            $this->auditService->record($request, $request->user(), $this->companyId($request), 'create', 'invoicing', $invoice, null, $invoice->withoutRelations()->toArray());
        }

        return new InvoiceResource($invoice);
    }

    public function show(Request $request, string $invoice): InvoiceResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'accounting.view'), 403);

        return new InvoiceResource($this->query($request)->with(['company', 'customer', 'lines', 'journal'])->findOrFail($invoice));
    }

    public function update(UpdateInvoiceRequest $request, string $invoice): InvoiceResource
    {
        $model = $this->query($request)->findOrFail($invoice);
        $old = $model->load('lines')->toArray();
        $model = $this->invoiceService->update($this->companyId($request), $request->user(), $model, $request->validated());
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'update', 'invoicing', $model, $old, $model->withoutRelations()->toArray());

        return new InvoiceResource($model);
    }

    public function destroy(Request $request, string $invoice): Response
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'accounting.edit'), 403);
        $model = $this->query($request)->findOrFail($invoice);
        $old = $model->load('lines')->toArray();
        $this->invoiceService->deleteDraft($this->companyId($request), $model);
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'delete', 'invoicing', $model, $old, null);

        return response()->noContent();
    }

    private function applyFilters(Builder $query, Request $request): void
    {
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
        if ($request->filled('fbr_status') && $request->string('fbr_status')->toString() !== 'all') {
            $query->where('fbr_status', $request->string('fbr_status')->toString());
        }
        $status = $request->string('status')->toString();
        if ($status === 'overdue' || $request->boolean('overdue_only')) {
            $query->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])->where('balance_due', '>', 0)->whereDate('due_date', '<', today());
        } elseif ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }
    }

    private function query(Request $request): Builder
    {
        return Invoice::query()->where('company_id', $this->companyId($request));
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
