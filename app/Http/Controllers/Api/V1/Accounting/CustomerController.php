<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\StoreCustomerRequest;
use App\Http\Requests\Api\V1\Accounting\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Company;
use App\Models\Customer;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CustomerController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'accounting.view'), 403);
        $query = $this->query($request)->withSum(['invoices as outstanding' => fn (Builder $invoices) => $invoices->whereNotNull('journal_id')->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])], 'balance_due');
        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(fn (Builder $builder) => $builder->where('name', 'like', $search)->orWhere('code', 'like', $search)->orWhere('ntn', 'like', $search)->orWhere('cnic', 'like', $search));
        }
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return CustomerResource::collection($query->orderBy('name')->get());
    }

    public function store(StoreCustomerRequest $request): CustomerResource
    {
        $customer = DB::transaction(function () use ($request): Customer {
            Company::query()->lockForUpdate()->findOrFail($this->companyId($request));
            $sequence = (int) Customer::query()->where('company_id', $this->companyId($request))->max('sequence') + 1;
            $customer = Customer::query()->create([...$request->validated(), 'company_id' => $this->companyId($request), 'sequence' => $sequence, 'code' => $request->validated('code') ?? sprintf('CUS-%04d', $sequence), 'created_by' => $request->user()->id]);
            $this->auditService->record($request, $request->user(), $this->companyId($request), 'create', 'accounts_receivable', $customer, null, $customer->toArray());

            return $customer;
        });

        return new CustomerResource($customer);
    }

    public function show(Request $request, string $customer): CustomerResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'accounting.view'), 403);

        return new CustomerResource($this->query($request)->findOrFail($customer));
    }

    public function update(UpdateCustomerRequest $request, string $customer): CustomerResource
    {
        $model = $this->query($request)->findOrFail($customer);
        $old = $model->toArray();
        $model->update([...$request->validated(), 'updated_by' => $request->user()->id]);
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'update', 'accounts_receivable', $model, $old, $model->fresh()->toArray());

        return new CustomerResource($model->fresh());
    }

    public function destroy(Request $request, string $customer): Response
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'accounting.edit'), 403);
        $model = $this->query($request)->findOrFail($customer);
        abort_if($model->invoices()->exists(), 409, 'A customer with invoices cannot be deleted. Deactivate the customer instead.');
        $old = $model->toArray();
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'delete', 'accounts_receivable', $model, $old, null);
        $model->delete();

        return response()->noContent();
    }

    private function query(Request $request): Builder
    {
        return Customer::query()->where('company_id', $this->companyId($request));
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
