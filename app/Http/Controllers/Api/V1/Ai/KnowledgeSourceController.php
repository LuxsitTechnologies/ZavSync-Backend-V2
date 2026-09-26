<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Ai\StoreKnowledgeSourceRequest;
use App\Http\Requests\Api\V1\Ai\UpdateKnowledgeSourceRequest;
use App\Jobs\IngestKnowledgeSource;
use App\Models\Document;
use App\Models\KnowledgeIngestionRun;
use App\Models\KnowledgeSource;
use App\Services\AuditService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KnowledgeSourceController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly AuditService $audit, private readonly EntitlementService $entitlements) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'ai.knowledge.view');
        $query = KnowledgeSource::query()->where('company_id', $companyId)->with('document:id,company_id,original_filename,mime_type')->withCount('ingestionRuns');
        $permissions = $this->access->permissionNames($request->user(), $companyId);
        if (! in_array('*', $permissions, true)) {
            $query->whereIn('access_permission', $permissions);
        }
        if ($request->filled('status')) {
            $query->where('status', mb_strtoupper($request->string('status')->toString()));
        }

        return response()->json($query->latest()->paginate(30));
    }

    public function store(StoreKnowledgeSourceRequest $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $data = $request->validated();
        $this->entitlements->assertWithinLimit($companyId, 'knowledge_sources', KnowledgeSource::query()->where('company_id', $companyId)->count());
        $permission = $this->permission($request, $data);
        $document = isset($data['document_id']) ? Document::query()->where('company_id', $companyId)->findOrFail($data['document_id']) : null;
        [$source, $run] = DB::transaction(function () use ($request, $companyId, $data, $permission, $document): array {
            $source = KnowledgeSource::query()->create(['company_id' => $companyId, 'document_id' => $document?->id, 'source_type' => $data['source_type'], 'title' => $data['title'], 'content' => $data['source_type'] === 'NOTE' ? $data['content'] : null, 'access_permission' => $permission, 'status' => 'PENDING', 'checksum_sha256' => $document?->checksum_sha256 ?? hash('sha256', (string) $data['content']), 'version' => 1, 'created_by' => $request->user()->id]);
            $run = $this->newRun($request, $source);

            return [$source, $run];
        });
        IngestKnowledgeSource::dispatch($run->id)->afterCommit();
        $this->audit->record($request, $request->user(), $companyId, 'knowledge_source_created', 'ai', $source, null, ['id' => $source->id, 'source_type' => $source->source_type, 'access_permission' => $source->access_permission]);

        return response()->json(['source' => $source, 'ingestion_run' => $run], 201);
    }

    public function show(Request $request, string $source): JsonResponse
    {
        $model = $this->source($request, $source);
        $this->authorizeView($request, $model);

        return response()->json($model->load(['document:id,company_id,original_filename,mime_type,size_bytes', 'ingestionRuns' => fn ($query) => $query->latest()->limit(20)]));
    }

    public function update(UpdateKnowledgeSourceRequest $request, string $source): JsonResponse
    {
        $model = $this->source($request, $source);
        $this->authorizeView($request, $model);
        $data = $request->validated();
        if ($model->source_type === 'DOCUMENT' && array_key_exists('content', $data)) {
            throw ValidationException::withMessages(['content' => 'A document source reads content from the existing private document.']);
        }
        if (isset($data['access_permission']) && ! $request->user()->hasCompanyPermission($model->company_id, $data['access_permission'])) {
            throw new PlatformException('AI_KNOWLEDGE_PERMISSION_DENIED', 'You cannot assign a source permission you do not hold.', 403);
        }
        [$model, $run] = DB::transaction(function () use ($request, $model, $data): array {
            $model->update([...$data, 'status' => 'PENDING', 'version' => $model->version + 1, 'chunk_count' => 0, 'checksum_sha256' => isset($data['content']) ? hash('sha256', $data['content']) : $model->checksum_sha256, 'indexed_at' => null, 'failed_at' => null, 'updated_by' => $request->user()->id]);
            $run = $this->newRun($request, $model->fresh());

            return [$model->fresh(), $run];
        });
        IngestKnowledgeSource::dispatch($run->id)->afterCommit();
        $this->audit->record($request, $request->user(), $model->company_id, 'knowledge_source_updated', 'ai', $model, null, ['id' => $model->id, 'version' => $model->version, 'access_permission' => $model->access_permission]);

        return response()->json(['source' => $model, 'ingestion_run' => $run]);
    }

    public function ingest(Request $request, string $source): JsonResponse
    {
        $this->access->authorize($request->user(), $this->companyId($request), 'ai.knowledge.manage');
        $model = $this->source($request, $source);
        $this->authorizeView($request, $model);
        $run = $this->newRun($request, $model);
        $model->update(['status' => 'PENDING']);
        IngestKnowledgeSource::dispatch($run->id)->afterCommit();
        $this->audit->record($request, $request->user(), $model->company_id, 'knowledge_source_reindex_requested', 'ai', $model, null, ['id' => $model->id, 'version' => $model->version, 'ingestion_run_id' => $run->id]);

        return response()->json($run, 202);
    }

    public function destroy(Request $request, string $source): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'ai.knowledge.manage');
        $model = $this->source($request, $source);
        $this->authorizeView($request, $model);
        $this->audit->record($request, $request->user(), $companyId, 'knowledge_source_deleted', 'ai', $model, ['id' => $model->id], null);
        DB::transaction(function () use ($model): void {
            $model->chunks()->delete();
            $model->delete();
        });

        return response()->json(['message' => 'Knowledge source deleted.']);
    }

    private function newRun(Request $request, KnowledgeSource $source): KnowledgeIngestionRun
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            throw new PlatformException('IDEMPOTENCY_KEY_REQUIRED', 'An Idempotency-Key header is required.', 422);
        }
        $existing = KnowledgeIngestionRun::query()->where('company_id', $source->company_id)->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            if ($existing->knowledge_source_id !== $source->id || $existing->source_version !== $source->version) {
                throw new PlatformException('IDEMPOTENCY_KEY_REUSED', 'The idempotency key was already used for a different ingestion.', 409);
            }

            return $existing;
        }

        return KnowledgeIngestionRun::query()->create(['company_id' => $source->company_id, 'knowledge_source_id' => $source->id, 'idempotency_key' => $key, 'source_version' => $source->version, 'status' => 'PENDING', 'requested_by' => $request->user()->id]);
    }

    /** @param array<string, mixed> $data */
    private function permission(Request $request, array $data): string
    {
        $permission = $data['access_permission'];
        if (! $request->user()->hasCompanyPermission($this->companyId($request), $permission)) {
            throw new PlatformException('AI_KNOWLEDGE_PERMISSION_DENIED', 'You cannot assign a source permission you do not hold.', 403);
        }
        if ($data['source_type'] !== 'DOCUMENT') {
            return $permission;
        }
        $document = Document::query()->where('company_id', $this->companyId($request))->findOrFail($data['document_id']);
        $required = match (class_basename($document->documentable_type)) {
            'Customer', 'Invoice' => 'accounting.view',
            'Supplier', 'SupplierBill' => 'payables.view',
            'PurchaseOrder' => 'purchase_orders.view',
            'InventoryItem' => 'inventory.view',
            'Employee', 'PayrollBatch' => 'payroll.view',
            'CrmAccount', 'CrmLead', 'CrmDeal' => 'crm.view',
            default => 'platform.documents.view',
        };
        if ($permission !== $required) {
            throw ValidationException::withMessages(['access_permission' => "This document inherits the {$required} permission from its related record."]);
        }

        return $required;
    }

    private function authorizeView(Request $request, KnowledgeSource $source): void
    {
        $this->access->authorize($request->user(), $source->company_id, 'ai.knowledge.view');
        abort_unless($request->user()->hasCompanyPermission($source->company_id, $source->access_permission), 404);
    }

    private function source(Request $request, string $id): KnowledgeSource
    {
        return KnowledgeSource::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
