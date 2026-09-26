<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiEvaluationRun;
use App\Models\AnomalyResult;
use App\Models\IntelligenceForecast;
use App\Services\Ai\ManagementBriefingService;
use App\Services\Ai\OperationalSignalService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntelligenceAnalyticsController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly ManagementBriefingService $briefings) {}

    public function anomalies(Request $request): JsonResponse
    {
        return response()->json($this->authorizedQuery($request, AnomalyResult::query())->latest('evaluated_at')->paginate(50));
    }

    public function forecasts(Request $request): JsonResponse
    {
        return response()->json($this->authorizedQuery($request, IntelligenceForecast::query())->latest('generated_at')->paginate(50));
    }

    public function briefing(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'intelligence.briefings.view');
        $data = $request->validate(['period' => ['sometimes', 'in:TODAY,THIS_WEEK,THIS_MONTH'], 'with_ai' => ['sometimes', 'boolean']]);

        return response()->json($this->briefings->prepare($companyId, $request->user(), $data['period'] ?? 'TODAY', (bool) ($data['with_ai'] ?? false)));
    }

    public function evaluations(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'intelligence.evaluations.manage');
        $runs = AiEvaluationRun::query()->where('company_id', $companyId)->latest()->limit(200)->get();
        $completed = $runs->where('status', 'COMPLETED');
        $checks = $completed->flatMap(fn (AiEvaluationRun $run) => $run->checks ?? []);
        $byCheck = $checks->groupBy('name')->map(fn ($rows, string $name): array => ['name' => $name, 'runs' => $rows->count(), 'passed' => $rows->where('passed', true)->count(), 'pass_rate_bps' => $rows->isEmpty() ? 0 : intdiv($rows->where('passed', true)->count() * 10000, $rows->count())])->values();

        return response()->json(['runs' => $runs, 'summary' => ['total' => $runs->count(), 'completed' => $completed->count(), 'average_score_bps' => $completed->isEmpty() ? null : intdiv((int) $completed->sum('score_bps'), $completed->count()), 'checks' => $byCheck]]);
    }

    private function authorizedQuery(Request $request, Builder $query): Builder
    {
        $companyId = $this->companyId($request);
        $permission = str_contains($request->path(), 'anomalies') ? 'intelligence.anomalies.view' : 'intelligence.forecasts.view';
        $this->access->authorize($request->user(), $companyId, $permission);
        $query->where('company_id', $companyId);
        $permissions = $this->access->permissionNames($request->user(), $companyId);
        if (! in_array('*', $permissions, true)) {
            $modules = collect(OperationalSignalService::SOURCE_PERMISSIONS)->filter(fn (string $sourcePermission): bool => in_array($sourcePermission, $permissions, true))->keys();
            $query->whereIn('source_module', $modules);
        }

        return $query;
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
