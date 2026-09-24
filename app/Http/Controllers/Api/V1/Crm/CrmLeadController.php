<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Exceptions\CrmException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Crm\ConvertCrmLeadRequest;
use App\Http\Requests\Api\V1\Crm\StoreCrmLeadRequest;
use App\Http\Requests\Api\V1\Crm\TransitionCrmLeadRequest;
use App\Http\Requests\Api\V1\Crm\UpdateCrmLeadRequest;
use App\Http\Resources\CrmLeadResource;
use App\Models\CrmLead;
use App\Services\AuditService;
use App\Services\Crm\CrmLeadConversionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class CrmLeadController extends Controller
{
    public function __construct(private readonly AuditService $audit, private readonly CrmLeadConversionService $conversion) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);
        $query = $this->query($request)->with(['account', 'owner'])->withMax('activities', 'created_at');
        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';
            $query->where(fn (Builder $builder) => $builder->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('company_name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term));
        }
        foreach (['status', 'owner_id', 'source', 'account_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->boolean('mine')) {
            $query->where('owner_id', $request->user()->id);
        }
        if ($request->filled('score_min')) {
            $query->where('score', '>=', $request->integer('score_min'));
        }
        if ($request->filled('score_max')) {
            $query->where('score', '<=', $request->integer('score_max'));
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

        return CrmLeadResource::collection($query->latest()->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function store(StoreCrmLeadRequest $request): CrmLeadResource
    {
        $lead = CrmLead::query()->create([...$request->validated(), 'company_id' => $this->companyId($request), 'created_by' => $request->user()->id]);
        $this->record($request, 'create', $lead);

        return new CrmLeadResource($lead->load(['account', 'owner'])->loadMax('activities', 'created_at'));
    }

    public function show(Request $request, string $lead): CrmLeadResource
    {
        $this->authorizeView($request);

        return new CrmLeadResource($this->lead($request, $lead)->load(['account', 'owner', 'scoreEvents.rule', 'convertedAccount', 'convertedContact', 'convertedDeal'])->loadMax('activities', 'created_at'));
    }

    public function update(UpdateCrmLeadRequest $request, string $lead): CrmLeadResource
    {
        $model = $this->lead($request, $lead);
        if ($model->status === 'CONVERTED') {
            throw new CrmException('CRM_LEAD_CONVERTED', 'A converted lead cannot be edited.', 409);
        }
        $old = $model->toArray();
        $data = $request->validated();
        unset($data['status']);
        $model->update([...$data, 'updated_by' => $request->user()->id]);
        $this->record($request, 'update', $model, $old);

        return new CrmLeadResource($model->fresh()->load(['account', 'owner'])->loadMax('activities', 'created_at'));
    }

    public function destroy(Request $request, string $lead): Response
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.leads.manage'), 403);
        $model = $this->lead($request, $lead);
        if ($model->status === 'CONVERTED') {
            throw new CrmException('CRM_LEAD_CONVERTED', 'A converted lead must be preserved.', 409);
        }
        $old = $model->toArray();
        $this->audit->record($request, $request->user(), $this->companyId($request), 'delete', 'crm_lead', $model, $old, null);
        $model->delete();

        return response()->noContent();
    }

    public function transition(TransitionCrmLeadRequest $request, string $lead): CrmLeadResource
    {
        $model = $this->lead($request, $lead);
        $allowed = ['NEW' => ['CONTACTED', 'QUALIFIED', 'UNQUALIFIED', 'LOST'], 'CONTACTED' => ['NEW', 'QUALIFIED', 'UNQUALIFIED', 'LOST'], 'QUALIFIED' => ['CONTACTED', 'UNQUALIFIED', 'LOST'], 'UNQUALIFIED' => ['NEW'], 'LOST' => ['NEW'], 'CONVERTED' => []];
        $next = $request->validated('status');
        if ($next !== $model->status && ! in_array($next, $allowed[$model->status] ?? [], true)) {
            throw new CrmException('CRM_LEAD_TRANSITION_INVALID', "A {$model->status} lead cannot transition to $next.", 409);
        }
        $old = $model->toArray();
        $model->update(['status' => $next, 'qualification_notes' => $request->validated('qualification_notes'), 'updated_by' => $request->user()->id]);
        $this->record($request, 'status_change', $model, $old);

        return new CrmLeadResource($model->fresh()->load(['account', 'owner'])->loadMax('activities', 'created_at'));
    }

    public function convert(ConvertCrmLeadRequest $request, string $lead): CrmLeadResource
    {
        return new CrmLeadResource($this->conversion->convert($request, $this->lead($request, $lead), $request->validated()));
    }

    private function query(Request $request): Builder
    {
        return CrmLead::query()->where('company_id', $this->companyId($request));
    }

    private function lead(Request $request, string $id): CrmLead
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
    private function record(Request $request, string $action, CrmLead $lead, ?array $old = null): void
    {
        $this->audit->record($request, $request->user(), $this->companyId($request), $action, 'crm_lead', $lead, $old, $lead->fresh()->toArray());
    }
}
