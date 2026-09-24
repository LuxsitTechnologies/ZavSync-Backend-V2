<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Crm\StoreCrmScoreRuleRequest;
use App\Http\Resources\CrmScoreRuleResource;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmScoreRule;
use App\Services\AuditService;
use App\Services\Crm\CrmScoringService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class CrmScoreController extends Controller
{
    public function __construct(private readonly CrmScoringService $scoring, private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);

        return CrmScoreRuleResource::collection(CrmScoreRule::query()->where('company_id', $this->companyId($request))->orderBy('target_type')->orderBy('position')->get());
    }

    public function store(StoreCrmScoreRuleRequest $request): CrmScoreRuleResource
    {
        $rule = CrmScoreRule::query()->create([...$request->validated(), 'company_id' => $this->companyId($request), 'created_by' => $request->user()->id]);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'create', 'crm_score_rule', $rule, null, $rule->toArray());

        return new CrmScoreRuleResource($rule);
    }

    public function update(StoreCrmScoreRuleRequest $request, string $rule): CrmScoreRuleResource
    {
        $model = $this->rule($request, $rule);
        $old = $model->toArray();
        $model->update([...$request->validated(), 'updated_by' => $request->user()->id]);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'update', 'crm_score_rule', $model, $old, $model->fresh()->toArray());

        return new CrmScoreRuleResource($model->fresh());
    }

    public function destroy(Request $request, string $rule): Response
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.scoring.manage'), 403);
        $model = $this->rule($request, $rule);
        $old = $model->toArray();
        $this->audit->record($request, $request->user(), $this->companyId($request), 'delete', 'crm_score_rule', $model, $old, null);
        $model->delete();

        return response()->noContent();
    }

    public function recalculate(Request $request, string $type, string $id): JsonResponse
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.scoring.manage'), 403);
        $record = $this->record($request, $type, $id);
        $result = $this->scoring->recalculate($record, $this->companyId($request), (int) $request->user()->id);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'recalculate', 'crm_score', $record, null, ['score' => $result['score']]);

        return response()->json(['data' => $result]);
    }

    private function record(Request $request, string $type, string $id): Model
    {
        $class = match (strtolower($type)) {
            'lead' => CrmLead::class, 'deal' => CrmDeal::class, default => abort(404)
        };

        return $class::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }

    private function rule(Request $request, string $id): CrmScoreRule
    {
        return CrmScoreRule::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.view'), 403);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
