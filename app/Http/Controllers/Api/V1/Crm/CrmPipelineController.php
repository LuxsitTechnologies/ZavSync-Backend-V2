<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Exceptions\CrmException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Crm\StoreCrmPipelineRequest;
use App\Http\Requests\Api\V1\Crm\UpdateCrmPipelineRequest;
use App\Http\Resources\CrmPipelineResource;
use App\Models\CrmPipeline;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CrmPipelineController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);
        $query = $this->query($request)->with('stages')->withCount('deals');
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return CrmPipelineResource::collection($query->orderByDesc('is_default')->orderBy('name')->get());
    }

    public function store(StoreCrmPipelineRequest $request): CrmPipelineResource
    {
        $pipeline = DB::transaction(function () use ($request): CrmPipeline {
            if ($request->boolean('is_default')) {
                $this->query($request)->lockForUpdate()->update(['is_default' => false]);
            }
            $data = $request->safe()->except('stages');
            $pipeline = CrmPipeline::query()->create([...$data, 'company_id' => $this->companyId($request), 'created_by' => $request->user()->id]);
            foreach ($request->validated('stages') as $stage) {
                $pipeline->stages()->create([...$stage, 'company_id' => $this->companyId($request)]);
            }
            $this->record($request, 'create', $pipeline);

            return $pipeline;
        });

        return new CrmPipelineResource($pipeline->load('stages')->loadCount('deals'));
    }

    public function show(Request $request, string $pipeline): CrmPipelineResource
    {
        $this->authorizeView($request);

        return new CrmPipelineResource($this->pipeline($request, $pipeline)->load('stages')->loadCount('deals'));
    }

    public function update(UpdateCrmPipelineRequest $request, string $pipeline): CrmPipelineResource
    {
        $model = DB::transaction(function () use ($request, $pipeline): CrmPipeline {
            $model = $this->query($request)->lockForUpdate()->findOrFail($pipeline);
            $old = $model->load('stages')->toArray();
            if ($request->boolean('is_default')) {
                $this->query($request)->whereKeyNot($model->id)->update(['is_default' => false]);
            }
            $model->update([...$request->safe()->except('stages'), 'updated_by' => $request->user()->id]);
            $incomingIds = collect($request->validated('stages'))->pluck('id')->filter();
            if ($model->stages()->whereIn('id', $incomingIds)->count() !== $incomingIds->count()) {
                throw new CrmException('CRM_STAGE_PIPELINE_MISMATCH', 'Every existing stage must belong to the selected pipeline.');
            }
            $removed = $model->stages()->whereNotIn('id', $incomingIds)->get();
            foreach ($removed as $stage) {
                if ($stage->deals()->exists()) {
                    throw new CrmException('CRM_STAGE_IN_USE', 'A pipeline stage used by deals cannot be removed.', 409);
                }
                $stage->delete();
            }
            $model->stages()->update(['position' => DB::raw('position + 10000')]);
            foreach ($request->validated('stages') as $stageData) {
                $stageId = $stageData['id'] ?? null;
                unset($stageData['id']);
                if ($stageId) {
                    $model->stages()->whereKey($stageId)->update($stageData);
                } else {
                    $model->stages()->create([...$stageData, 'company_id' => $this->companyId($request)]);
                }
            }
            $this->record($request, 'update', $model, $old);

            return $model;
        });

        return new CrmPipelineResource($model->fresh()->load('stages')->loadCount('deals'));
    }

    public function destroy(Request $request, string $pipeline): Response
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.pipelines.manage'), 403);
        $model = $this->pipeline($request, $pipeline);
        if ($model->deals()->exists()) {
            throw new CrmException('CRM_PIPELINE_IN_USE', 'A pipeline with deals cannot be deleted. Deactivate it instead.', 409);
        }
        $old = $model->load('stages')->toArray();
        $this->audit->record($request, $request->user(), $this->companyId($request), 'delete', 'crm_pipeline', $model, $old, null);
        $model->delete();

        return response()->noContent();
    }

    private function query(Request $request): Builder
    {
        return CrmPipeline::query()->where('company_id', $this->companyId($request));
    }

    private function pipeline(Request $request, string $id): CrmPipeline
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
    private function record(Request $request, string $action, CrmPipeline $pipeline, ?array $old = null): void
    {
        $this->audit->record($request, $request->user(), $this->companyId($request), $action, 'crm_pipeline', $pipeline, $old, $pipeline->fresh()->load('stages')->toArray());
    }
}
