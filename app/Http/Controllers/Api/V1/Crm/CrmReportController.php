<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Http\Resources\CrmActivityResource;
use App\Models\CompanyUser;
use App\Models\CrmActivity;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmReportController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $this->authorize($request);
        $companyId = $this->companyId($request);
        $leadCounts = CrmLead::query()->where('company_id', $companyId)->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $dealCounts = CrmDeal::query()->where('company_id', $companyId)->selectRaw('status, COUNT(*) AS aggregate, COALESCE(SUM(amount), 0) AS amount')->groupBy('status')->get()->keyBy('status');
        $converted = (int) ($leadCounts['CONVERTED'] ?? 0);
        $leadTotal = (int) $leadCounts->sum();
        $openPipeline = CrmDeal::query()->where('company_id', $companyId)->where('status', 'OPEN')->selectRaw('COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(amount * probability_bps), 0) AS weighted')->first();
        $activities = CrmActivity::query()->where('company_id', $companyId)->selectRaw("SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN status = 'PENDING' AND due_at < ? THEN 1 ELSE 0 END) AS overdue", [now()])->first();

        return response()->json(['data' => [
            'openLeads' => (int) $leadCounts->except(['CONVERTED', 'LOST', 'UNQUALIFIED'])->sum(), 'qualifiedLeads' => (int) ($leadCounts['QUALIFIED'] ?? 0),
            'convertedLeads' => $converted, 'leadConversionBasisPoints' => $leadTotal > 0 ? intdiv($converted * 10000, $leadTotal) : 0,
            'openDeals' => (int) ($dealCounts['OPEN']?->aggregate ?? 0), 'pipelineValue' => (int) ($openPipeline->amount ?? 0),
            'weightedPipelineValue' => intdiv((int) ($openPipeline->weighted ?? 0), 10000),
            'wonDeals' => (int) ($dealCounts['WON']?->aggregate ?? 0), 'wonDealValue' => (int) ($dealCounts['WON']?->amount ?? 0),
            'lostDeals' => (int) ($dealCounts['LOST']?->aggregate ?? 0), 'lostDealValue' => (int) ($dealCounts['LOST']?->amount ?? 0),
            'activitiesDue' => (int) ($activities->pending ?? 0), 'overdueActivities' => (int) ($activities->overdue ?? 0),
            'dealsByStage' => $this->dealsByStage($companyId), 'leadsBySource' => $this->leadsBySource($companyId),
        ]]);
    }

    public function owners(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.view'), 403);
        $owners = CompanyUser::query()->where('company_id', $this->companyId($request))->where('is_active', true)->with('user:id,name,email')->get()->map(fn (CompanyUser $membership): array => ['id' => $membership->user_id, 'name' => $membership->user->name, 'email' => $membership->user->email]);

        return response()->json(['data' => $owners]);
    }

    public function pipeline(Request $request): JsonResponse
    {
        $this->authorize($request);
        $companyId = $this->companyId($request);
        $totals = CrmDeal::query()->where('company_id', $companyId)->selectRaw('status, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS value, COALESCE(SUM(amount * probability_bps), 0) AS weighted')->groupBy('status')->get()->map(fn ($row): array => ['status' => $row->status, 'count' => (int) $row->count, 'value' => (int) $row->value, 'weightedValue' => intdiv((int) $row->weighted, 10000)]);
        $owners = CrmDeal::query()->where('crm_deals.company_id', $companyId)->leftJoin('users', 'users.id', '=', 'crm_deals.owner_id')->selectRaw("owner_id, users.name AS owner, COUNT(*) AS deals, SUM(CASE WHEN status = 'WON' THEN 1 ELSE 0 END) AS won, COALESCE(SUM(CASE WHEN status = 'WON' THEN amount ELSE 0 END), 0) AS won_value")->groupBy('owner_id', 'users.name')->withCasts(['deals' => 'integer', 'won' => 'integer', 'won_value' => 'integer'])->get();
        $closeDistribution = CrmDeal::query()->where('company_id', $companyId)->whereNotNull('expected_close_date')->selectRaw('expected_close_date, COUNT(*) AS deals, COALESCE(SUM(amount), 0) AS value')->groupBy('expected_close_date')->orderBy('expected_close_date')->withCasts(['deals' => 'integer', 'value' => 'integer'])->get();

        return response()->json(['data' => ['byStage' => $this->dealsByStage($companyId), 'totals' => $totals, 'owners' => $owners, 'expectedCloseDistribution' => $closeDistribution, 'leadSourceConversion' => $this->leadSourceConversion($companyId)]]);
    }

    public function activities(Request $request): JsonResponse
    {
        $this->authorize($request);
        $companyId = $this->companyId($request);
        $byType = CrmActivity::query()->where('company_id', $companyId)->selectRaw('type, status, COUNT(*) AS count')->groupBy('type', 'status')->withCasts(['count' => 'integer'])->get();
        $byOwner = CrmActivity::query()->where('crm_activities.company_id', $companyId)->leftJoin('users', 'users.id', '=', 'crm_activities.owner_id')->selectRaw("owner_id, users.name AS owner, COUNT(*) AS total, SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) AS completed, SUM(CASE WHEN status = 'PENDING' AND due_at < ? THEN 1 ELSE 0 END) AS overdue", [now()])->groupBy('owner_id', 'users.name')->withCasts(['total' => 'integer', 'completed' => 'integer', 'overdue' => 'integer'])->get();
        $recent = CrmActivity::query()->where('company_id', $companyId)->with(['activityable', 'owner', 'creator'])->latest()->limit(20)->get();

        return response()->json(['data' => ['byType' => $byType, 'byOwner' => $byOwner, 'recent' => CrmActivityResource::collection($recent)]]);
    }

    private function dealsByStage(string $companyId): mixed
    {
        return CrmDeal::query()->where('crm_deals.company_id', $companyId)->join('crm_pipeline_stages', 'crm_pipeline_stages.id', '=', 'crm_deals.pipeline_stage_id')->join('crm_pipelines', 'crm_pipelines.id', '=', 'crm_deals.pipeline_id')->selectRaw('crm_pipelines.id AS pipeline_id, crm_pipelines.name AS pipeline, crm_pipeline_stages.id AS stage_id, crm_pipeline_stages.name AS stage, crm_pipeline_stages.position, COUNT(*) AS deals, COALESCE(SUM(crm_deals.amount), 0) AS value, COALESCE(SUM(crm_deals.amount * crm_deals.probability_bps), 0) AS weighted')->groupBy('crm_pipelines.id', 'crm_pipelines.name', 'crm_pipeline_stages.id', 'crm_pipeline_stages.name', 'crm_pipeline_stages.position')->orderBy('crm_pipelines.name')->orderBy('crm_pipeline_stages.position')->get()->map(fn ($row): array => ['pipelineId' => $row->pipeline_id, 'pipeline' => $row->pipeline, 'stageId' => $row->stage_id, 'stage' => $row->stage, 'deals' => (int) $row->deals, 'value' => (int) $row->value, 'weightedValue' => intdiv((int) $row->weighted, 10000)]);
    }

    private function leadsBySource(string $companyId): mixed
    {
        return CrmLead::query()->where('company_id', $companyId)->selectRaw("COALESCE(source, 'Unspecified') AS source, COUNT(*) AS leads, SUM(CASE WHEN status = 'CONVERTED' THEN 1 ELSE 0 END) AS converted")->groupBy('source')->orderByDesc('leads')->withCasts(['leads' => 'integer', 'converted' => 'integer'])->get();
    }

    private function leadSourceConversion(string $companyId): mixed
    {
        return $this->leadsBySource($companyId)->map(fn ($row): array => ['source' => $row->source, 'leads' => (int) $row->leads, 'converted' => (int) $row->converted, 'conversionBasisPoints' => $row->leads > 0 ? intdiv((int) $row->converted * 10000, (int) $row->leads) : 0]);
    }

    private function authorize(Request $request): void
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.reports.view'), 403);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
