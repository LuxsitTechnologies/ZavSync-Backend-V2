<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Crm\CustomerHandoffRequest;
use App\Http\Requests\Api\V1\Crm\StoreCrmAccountRequest;
use App\Http\Requests\Api\V1\Crm\UpdateCrmAccountRequest;
use App\Http\Resources\CrmAccountResource;
use App\Http\Resources\CustomerResource;
use App\Models\CrmAccount;
use App\Models\Customer;
use App\Services\AuditService;
use App\Services\Crm\CrmCustomerHandoffService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class CrmAccountController extends Controller
{
    public function __construct(private readonly AuditService $audit, private readonly CrmCustomerHandoffService $handoff) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);
        $query = $this->query($request)->with(['owner', 'contacts'])->withCount(['contacts', 'deals'])->withMax('activities', 'created_at');
        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';
            $query->where(fn (Builder $builder) => $builder->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('ntn', 'like', $term));
        }
        foreach (['status', 'owner_id', 'source'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->filled('tag_id')) {
            $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey($request->string('tag_id')->toString()));
        }
        if ($request->filled('created_from')) {
            $query->whereDate('created_at', '>=', $request->date('created_from'));
        }
        if ($request->filled('created_to')) {
            $query->whereDate('created_at', '<=', $request->date('created_to'));
        }
        $query->where('is_archived', $request->boolean('archived'));

        return CrmAccountResource::collection($query->orderBy('name')->paginate($this->perPage($request)));
    }

    public function store(StoreCrmAccountRequest $request): CrmAccountResource
    {
        $account = CrmAccount::query()->create([...$request->validated(), 'company_id' => $this->companyId($request), 'created_by' => $request->user()->id]);
        $this->record($request, 'create', $account);

        return new CrmAccountResource($account->load(['owner', 'contacts'])->loadCount(['contacts', 'deals'])->loadMax('activities', 'created_at'));
    }

    public function show(Request $request, string $account): CrmAccountResource
    {
        $this->authorizeView($request);

        return new CrmAccountResource($this->account($request, $account)->load(['owner', 'contacts'])->loadCount(['contacts', 'deals'])->loadMax('activities', 'created_at'));
    }

    public function update(UpdateCrmAccountRequest $request, string $account): CrmAccountResource
    {
        $model = $this->account($request, $account);
        $old = $model->toArray();
        $model->update([...$request->validated(), 'updated_by' => $request->user()->id]);
        $this->record($request, 'update', $model, $old);

        return new CrmAccountResource($model->fresh()->load(['owner', 'contacts'])->loadCount(['contacts', 'deals'])->loadMax('activities', 'created_at'));
    }

    public function destroy(Request $request, string $account): Response
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.accounts.manage'), 403);
        $model = $this->account($request, $account);
        $old = $model->toArray();
        $model->update(['is_archived' => true, 'status' => 'INACTIVE', 'updated_by' => $request->user()->id]);
        $this->record($request, 'archive', $model, $old);

        return response()->noContent();
    }

    public function customerMatches(Request $request, string $account): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.customer.convert'), 403);
        $model = $this->account($request, $account);
        $matches = Customer::query()->where('company_id', $this->companyId($request))->where(fn (Builder $query) => $query->whereRaw('LOWER(name) = ?', [mb_strtolower($model->name)])->when($model->ntn, fn (Builder $query, string $ntn) => $query->orWhere('ntn', $ntn))->when($model->cnic, fn (Builder $query, string $cnic) => $query->orWhere('cnic', $cnic))->when($model->email, fn (Builder $query, string $email) => $query->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)])))->limit(20)->get();

        return CustomerResource::collection($matches);
    }

    public function customerHandoff(CustomerHandoffRequest $request, string $account): CustomerResource
    {
        return new CustomerResource($this->handoff->handoff($request, $this->account($request, $account), $request->validated()));
    }

    private function query(Request $request): Builder
    {
        return CrmAccount::query()->where('company_id', $this->companyId($request));
    }

    private function account(Request $request, string $id): CrmAccount
    {
        return $this->query($request)->findOrFail($id);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.view'), 403);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, $request->integer('per_page', 25)));
    }

    /** @param array<string, mixed>|null $old */
    private function record(Request $request, string $action, CrmAccount $account, ?array $old = null): void
    {
        $this->audit->record($request, $request->user(), $this->companyId($request), $action, 'crm_account', $account, $old, $account->fresh()->toArray());
    }
}
