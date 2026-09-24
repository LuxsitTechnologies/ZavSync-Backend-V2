<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Crm\StoreCrmContactRequest;
use App\Http\Requests\Api\V1\Crm\UpdateCrmContactRequest;
use App\Http\Resources\CrmContactResource;
use App\Models\CrmContact;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CrmContactController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);
        $query = $this->query($request)->with(['account', 'owner'])->withMax('activities', 'created_at');
        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';
            $query->where(fn (Builder $builder) => $builder->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term));
        }
        foreach (['account_id', 'owner_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->filled('tag_id')) {
            $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey($request->string('tag_id')->toString()));
        }

        return CrmContactResource::collection($query->orderBy('first_name')->orderBy('last_name')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function store(StoreCrmContactRequest $request): CrmContactResource
    {
        $contact = DB::transaction(function () use ($request): CrmContact {
            if ($request->boolean('is_primary') && $request->validated('account_id')) {
                CrmContact::query()->where('company_id', $this->companyId($request))->where('account_id', $request->validated('account_id'))->lockForUpdate()->update(['is_primary' => false]);
            }

            return CrmContact::query()->create([...$request->validated(), 'company_id' => $this->companyId($request), 'created_by' => $request->user()->id]);
        });
        $this->record($request, 'create', $contact);

        return new CrmContactResource($contact->load(['account', 'owner'])->loadMax('activities', 'created_at'));
    }

    public function show(Request $request, string $contact): CrmContactResource
    {
        $this->authorizeView($request);

        return new CrmContactResource($this->contact($request, $contact)->load(['account', 'owner'])->loadMax('activities', 'created_at'));
    }

    public function update(UpdateCrmContactRequest $request, string $contact): CrmContactResource
    {
        $model = DB::transaction(function () use ($request, $contact): CrmContact {
            $model = $this->query($request)->lockForUpdate()->findOrFail($contact);
            if ($request->boolean('is_primary') && $request->validated('account_id')) {
                CrmContact::query()->where('company_id', $this->companyId($request))->where('account_id', $request->validated('account_id'))->whereKeyNot($model->id)->update(['is_primary' => false]);
            }
            $old = $model->toArray();
            $model->update([...$request->validated(), 'updated_by' => $request->user()->id]);
            $this->record($request, 'update', $model, $old);

            return $model;
        });

        return new CrmContactResource($model->fresh()->load(['account', 'owner'])->loadMax('activities', 'created_at'));
    }

    public function destroy(Request $request, string $contact): Response
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.contacts.manage'), 403);
        $model = $this->contact($request, $contact);
        $old = $model->toArray();
        $model->update(['status' => 'INACTIVE', 'updated_by' => $request->user()->id]);
        $this->record($request, 'deactivate', $model, $old);

        return response()->noContent();
    }

    private function query(Request $request): Builder
    {
        return CrmContact::query()->where('company_id', $this->companyId($request));
    }

    private function contact(Request $request, string $id): CrmContact
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

    /** @param array<string, mixed>|null $old */
    private function record(Request $request, string $action, CrmContact $contact, ?array $old = null): void
    {
        $this->audit->record($request, $request->user(), $this->companyId($request), $action, 'crm_contact', $contact, $old, $contact->fresh()->toArray());
    }
}
