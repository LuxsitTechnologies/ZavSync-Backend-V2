<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Exceptions\CrmException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Crm\CustomerHandoffRequest;
use App\Http\Requests\Api\V1\Crm\StoreCrmDealRequest;
use App\Http\Requests\Api\V1\Crm\TransitionCrmDealRequest;
use App\Http\Requests\Api\V1\Crm\UpdateCrmDealRequest;
use App\Http\Resources\CrmDealResource;
use App\Http\Resources\CustomerResource;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\CrmPipelineStage;
use App\Services\AuditService;
use App\Services\Crm\CrmCustomerHandoffService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CrmDealController extends Controller
{
    public function __construct(private readonly AuditService $audit, private readonly CrmCustomerHandoffService $handoff) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);
        $query = $this->query($request)->with(['account', 'primaryContact', 'pipeline', 'stage', 'owner'])->withMax('activities', 'created_at');
        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';
            $query->where(fn (Builder $builder) => $builder->where('title', 'like', $term)->orWhereHas('account', fn (Builder $account) => $account->where('name', 'like', $term))->orWhereHas('primaryContact', fn (Builder $contact) => $contact->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)));
        }
        foreach (['status', 'owner_id', 'pipeline_id', 'pipeline_stage_id', 'source', 'account_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->boolean('mine')) {
            $query->where('owner_id', $request->user()->id);
        }
        if ($request->filled('expected_from')) {
            $query->whereDate('expected_close_date', '>=', $request->date('expected_from'));
        }
        if ($request->filled('expected_to')) {
            $query->whereDate('expected_close_date', '<=', $request->date('expected_to'));
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

        return CrmDealResource::collection($query->latest()->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function store(StoreCrmDealRequest $request): CrmDealResource
    {
        $stage = $this->validatedStage($request, $request->validated('pipeline_id'), $request->validated('pipeline_stage_id'));
        $this->validateContactAccount($request, $request->validated('account_id'), $request->validated('primary_contact_id'));
        $deal = CrmDeal::query()->create([...$request->validated(), 'company_id' => $this->companyId($request), 'probability_bps' => $request->validated('probability_bps') ?? $stage->probability_bps, 'status' => $stage->is_won ? 'WON' : ($stage->is_lost ? 'LOST' : 'OPEN'), 'closed_at' => ($stage->is_won || $stage->is_lost) ? now() : null, 'actual_close_date' => ($stage->is_won || $stage->is_lost) ? now()->toDateString() : null, 'created_by' => $request->user()->id]);
        $this->record($request, 'create', $deal);

        return new CrmDealResource($this->load($deal));
    }

    public function show(Request $request, string $deal): CrmDealResource
    {
        $this->authorizeView($request);

        return new CrmDealResource($this->load($this->deal($request, $deal)));
    }

    public function update(UpdateCrmDealRequest $request, string $deal): CrmDealResource
    {
        $model = $this->deal($request, $deal);
        if ($model->status !== 'OPEN') {
            throw new CrmException('CRM_DEAL_CLOSED', 'A won or lost deal cannot be edited. Reopen it through a valid open stage first.', 409);
        }
        $stage = $this->validatedStage($request, $request->validated('pipeline_id'), $request->validated('pipeline_stage_id'));
        if ($stage->is_won || $stage->is_lost) {
            throw new CrmException('CRM_DEAL_TRANSITION_REQUIRED', 'Use the stage transition action to close a deal.', 409);
        }
        $this->validateContactAccount($request, $request->validated('account_id'), $request->validated('primary_contact_id'));
        $old = $model->toArray();
        $model->update([...$request->validated(), 'status' => 'OPEN', 'updated_by' => $request->user()->id]);
        $this->record($request, 'update', $model, $old);

        return new CrmDealResource($this->load($model->fresh()));
    }

    public function destroy(Request $request, string $deal): Response
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.deals.manage'), 403);
        $model = $this->deal($request, $deal);
        if ($model->status !== 'OPEN') {
            throw new CrmException('CRM_DEAL_CLOSED', 'A closed deal cannot be deleted.', 409);
        }
        $old = $model->toArray();
        $this->audit->record($request, $request->user(), $this->companyId($request), 'delete', 'crm_deal', $model, $old, null);
        $model->delete();

        return response()->noContent();
    }

    public function transition(TransitionCrmDealRequest $request, string $deal): CrmDealResource
    {
        $model = DB::transaction(function () use ($request, $deal): CrmDeal {
            $model = $this->query($request)->lockForUpdate()->findOrFail($deal);
            $stage = $this->validatedStage($request, $model->pipeline_id, $request->validated('pipeline_stage_id'));
            $status = $stage->is_won ? 'WON' : ($stage->is_lost ? 'LOST' : 'OPEN');
            if ($status === 'LOST' && ! $request->filled('loss_reason')) {
                throw new CrmException('CRM_LOSS_REASON_REQUIRED', 'A loss reason is required when a deal is lost.');
            }
            $old = $model->toArray();
            $model->update(['pipeline_stage_id' => $stage->id, 'probability_bps' => $stage->probability_bps, 'status' => $status, 'loss_reason' => $status === 'LOST' ? $request->validated('loss_reason') : null, 'closed_at' => $status === 'OPEN' ? null : now(), 'actual_close_date' => $status === 'OPEN' ? null : now()->toDateString(), 'updated_by' => $request->user()->id]);
            $this->record($request, 'stage_change', $model, $old);

            return $model;
        });

        return new CrmDealResource($this->load($model->fresh()));
    }

    public function customerHandoff(CustomerHandoffRequest $request, string $deal): CustomerResource
    {
        return new CustomerResource($this->handoff->handoff($request, $this->deal($request, $deal), $request->validated()));
    }

    private function validatedStage(Request $request, string $pipelineId, string $stageId): CrmPipelineStage
    {
        $stage = CrmPipelineStage::query()->where('company_id', $this->companyId($request))->where('pipeline_id', $pipelineId)->where('is_active', true)->find($stageId);
        if (! $stage) {
            throw new CrmException('CRM_STAGE_PIPELINE_MISMATCH', 'The selected stage does not belong to the selected pipeline.');
        }

        return $stage;
    }

    private function validateContactAccount(Request $request, string $accountId, ?string $contactId): void
    {
        if ($contactId && ! CrmContact::query()->where('company_id', $this->companyId($request))->where('account_id', $accountId)->whereKey($contactId)->exists()) {
            throw new CrmException('CRM_CONTACT_ACCOUNT_MISMATCH', 'The primary contact must belong to the deal account.');
        }
    }

    private function load(CrmDeal $deal): CrmDeal
    {
        return $deal->load(['account', 'primaryContact', 'pipeline', 'stage', 'owner'])->loadMax('activities', 'created_at');
    }

    private function query(Request $request): Builder
    {
        return CrmDeal::query()->where('company_id', $this->companyId($request));
    }

    private function deal(Request $request, string $id): CrmDeal
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
    private function record(Request $request, string $action, CrmDeal $deal, ?array $old = null): void
    {
        $this->audit->record($request, $request->user(), $this->companyId($request), $action, 'crm_deal', $deal, $old, $deal->fresh()->toArray());
    }
}
