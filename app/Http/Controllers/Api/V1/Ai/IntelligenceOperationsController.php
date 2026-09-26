<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Contracts\VectorStore;
use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Ai\StoreProviderReconciliationRequest;
use App\Jobs\RefreshCompanyIntelligence;
use App\Models\AiProviderReconciliation;
use App\Models\ScheduledIntelligenceRun;
use App\Services\Ai\IntelligenceObservabilityService;
use App\Services\Ai\ProviderBillingReconciliationService;
use App\Services\AuditService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntelligenceOperationsController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly IntelligenceObservabilityService $observability, private readonly ProviderBillingReconciliationService $billing, private readonly VectorStore $vectors, private readonly AuditService $audit) {}

    public function refresh(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'intelligence.manage');
        $key = $this->idempotencyKey($request);
        RefreshCompanyIntelligence::dispatch($companyId, $key)->afterCommit();

        return response()->json(['status' => 'QUEUED', 'idempotency_key' => $key], 202);
    }

    public function runs(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), $this->companyId($request), 'intelligence.schedule.manage');

        return response()->json(ScheduledIntelligenceRun::query()->where('company_id', $this->companyId($request))->latest()->paginate(50));
    }

    public function observability(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), $this->companyId($request), 'intelligence.observability.view');

        return response()->json($this->observability->report($this->companyId($request), $request->query('from'), $request->query('to')) + ['vector_store' => ['driver' => $this->vectors->driver(), 'available' => $this->vectors->available(), 'production_external' => false]]);
    }

    public function reconciliations(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'intelligence.observability.view');
        if ($request->filled('period_start') && $request->filled('period_end')) {
            $this->billing->current($companyId, 'openai', $request->string('period_start')->toString(), $request->string('period_end')->toString());
        }

        return response()->json(AiProviderReconciliation::query()->where('company_id', $companyId)->latest('period_end')->paginate(30));
    }

    public function importReconciliation(StoreProviderReconciliationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $record = $this->billing->import($this->companyId($request), $request->user(), $data['provider'], $data['period_start'], $data['period_end'], $data['provider_cost_minor'], $data['provider_reference'] ?? null);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'import_ai_provider_reconciliation', 'ai', $record, null, $record->withoutRelations()->toArray());

        return response()->json($record, 201);
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
