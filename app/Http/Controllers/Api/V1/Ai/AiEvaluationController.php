<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Ai\StoreAiEvaluationCaseRequest;
use App\Models\AiEvaluationCase;
use App\Models\AiEvaluationRun;
use App\Services\Ai\AiEvaluationService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiEvaluationController extends Controller
{
    public function __construct(private readonly AiEvaluationService $evaluations, private readonly PlatformAccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'ai.evaluations.view');

        return response()->json(['cases' => AiEvaluationCase::query()->where('company_id', $companyId)->withCount('runs')->latest()->get(), 'runs' => AiEvaluationRun::query()->where('company_id', $companyId)->with('evaluationCase:id,name')->latest()->limit(100)->get()]);
    }

    public function store(StoreAiEvaluationCaseRequest $request): JsonResponse
    {
        $case = AiEvaluationCase::query()->create([...$request->validated(), 'company_id' => $this->companyId($request), 'created_by' => $request->user()->id]);

        return response()->json($case, 201);
    }

    public function run(Request $request, string $case): JsonResponse
    {
        $this->access->authorize($request->user(), $this->companyId($request), 'ai.evaluations.manage');
        $model = AiEvaluationCase::query()->where('company_id', $this->companyId($request))->findOrFail($case);

        return response()->json($this->evaluations->run($request, $request->user(), $this->companyId($request), $model), 201);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
