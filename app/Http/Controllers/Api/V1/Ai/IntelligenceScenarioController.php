<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Ai\StoreIntelligenceScenarioRequest;
use App\Models\IntelligenceScenario;
use App\Services\Ai\ScenarioAnalysisService;
use App\Services\AuditService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntelligenceScenarioController extends Controller
{
    public function __construct(private readonly ScenarioAnalysisService $scenarios, private readonly PlatformAccessService $access, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), $this->companyId($request), 'intelligence.scenarios.manage');

        return response()->json(IntelligenceScenario::query()->where('company_id', $this->companyId($request))->latest()->paginate(30));
    }

    public function store(StoreIntelligenceScenarioRequest $request): JsonResponse
    {
        $scenario = $this->scenarios->calculate($this->companyId($request), $request->user(), $request->validated(), $this->idempotencyKey($request));
        $this->audit->record($request, $request->user(), $this->companyId($request), 'calculate_intelligence_scenario', 'ai', $scenario, null, ['scenario_type' => $scenario->scenario_type, 'assumptions' => $scenario->assumptions]);

        return response()->json($scenario, 201);
    }

    public function show(Request $request, string $scenario): JsonResponse
    {
        $this->access->authorize($request->user(), $this->companyId($request), 'intelligence.scenarios.manage');

        return response()->json(IntelligenceScenario::query()->where('company_id', $this->companyId($request))->findOrFail($scenario));
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            throw new PlatformException('IDEMPOTENCY_KEY_REQUIRED', 'An Idempotency-Key header is required.', 422);
        }

        return $key;
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
