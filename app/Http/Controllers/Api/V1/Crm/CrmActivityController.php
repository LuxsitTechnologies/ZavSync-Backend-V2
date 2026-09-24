<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Crm\StoreCrmActivityRequest;
use App\Http\Requests\Api\V1\Crm\TransitionCrmActivityRequest;
use App\Http\Requests\Api\V1\Crm\UpdateCrmActivityRequest;
use App\Http\Resources\CrmActivityResource;
use App\Models\CrmAccount;
use App\Models\CrmActivity;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class CrmActivityController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);
        $query = $this->query($request)->with(['activityable', 'owner', 'creator']);
        foreach (['type', 'status', 'owner_id', 'priority'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->boolean('mine')) {
            $query->where('owner_id', $request->user()->id);
        }
        if ($request->boolean('overdue')) {
            $query->where('status', 'PENDING')->where('due_at', '<', now());
        }
        if ($request->filled('due_from')) {
            $query->where('due_at', '>=', $request->date('due_from')->startOfDay());
        }
        if ($request->filled('due_to')) {
            $query->where('due_at', '<=', $request->date('due_to')->endOfDay());
        }
        if ($request->filled('related_type') && $request->filled('related_id')) {
            $query->where('activityable_type', $this->relatedClass($request->string('related_type')->toString()))->where('activityable_id', $request->string('related_id')->toString());
        }

        return CrmActivityResource::collection($query->orderByDesc('created_at')->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function store(StoreCrmActivityRequest $request): CrmActivityResource
    {
        $data = $request->validated();
        $relatedType = $data['related_type'] ?? null;
        $relatedId = $data['related_id'] ?? null;
        unset($data['related_type'], $data['related_id']);
        $activity = CrmActivity::query()->create([...$data, 'company_id' => $this->companyId($request), 'activityable_type' => $relatedType ? $this->relatedClass($relatedType) : null, 'activityable_id' => $relatedId, 'completed_at' => ($data['status'] ?? 'PENDING') === 'COMPLETED' ? now() : null, 'created_by' => $request->user()->id]);
        $this->record($request, 'create', $activity);

        return new CrmActivityResource($activity->load(['activityable', 'owner', 'creator']));
    }

    public function show(Request $request, string $activity): CrmActivityResource
    {
        $this->authorizeView($request);

        return new CrmActivityResource($this->activity($request, $activity)->load(['activityable', 'owner', 'creator']));
    }

    public function update(UpdateCrmActivityRequest $request, string $activity): CrmActivityResource
    {
        $model = $this->activity($request, $activity);
        $old = $model->toArray();
        $data = $request->validated();
        $relatedType = $data['related_type'] ?? null;
        $relatedId = $data['related_id'] ?? null;
        unset($data['related_type'], $data['related_id'], $data['status']);
        $model->update([...$data, 'activityable_type' => $relatedType ? $this->relatedClass($relatedType) : null, 'activityable_id' => $relatedId, 'updated_by' => $request->user()->id]);
        $this->record($request, 'update', $model, $old);

        return new CrmActivityResource($model->fresh()->load(['activityable', 'owner', 'creator']));
    }

    public function destroy(Request $request, string $activity): Response
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.activities.manage'), 403);
        $model = $this->activity($request, $activity);
        $old = $model->toArray();
        $this->audit->record($request, $request->user(), $this->companyId($request), 'delete', 'crm_activity', $model, $old, null);
        $model->delete();

        return response()->noContent();
    }

    public function transition(TransitionCrmActivityRequest $request, string $activity): CrmActivityResource
    {
        $model = $this->activity($request, $activity);
        $old = $model->toArray();
        $status = $request->validated('status');
        $model->update(['status' => $status, 'completed_at' => $status === 'COMPLETED' ? now() : null, 'outcome' => $request->validated('outcome'), 'updated_by' => $request->user()->id]);
        $this->record($request, $status === 'COMPLETED' ? 'complete' : 'reopen', $model, $old);

        return new CrmActivityResource($model->fresh()->load(['activityable', 'owner', 'creator']));
    }

    private function relatedClass(string $type): string
    {
        return match (strtolower($type)) {
            'account' => CrmAccount::class, 'contact' => CrmContact::class, 'lead' => CrmLead::class, 'deal' => CrmDeal::class,
            default => abort(422, 'Unsupported CRM related entity type.'),
        };
    }

    private function query(Request $request): Builder
    {
        return CrmActivity::query()->where('company_id', $this->companyId($request));
    }

    private function activity(Request $request, string $id): CrmActivity
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
    private function record(Request $request, string $action, CrmActivity $activity, ?array $old = null): void
    {
        $this->audit->record($request, $request->user(), $this->companyId($request), $action, 'crm_activity', $activity, $old, $activity->fresh()->toArray());
    }
}
