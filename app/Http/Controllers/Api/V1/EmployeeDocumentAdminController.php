<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Document;
use App\Models\Employee;
use App\Services\AuditService;
use App\Services\Platform\DocumentService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\NotificationService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentAdminController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly PlatformAccessService $access,
        private readonly EntitlementService $entitlements,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'employee.documents.admin.view');
        $data = $request->validate(['employee_id' => ['required', 'uuid'], 'per_page' => ['sometimes', 'integer', 'between:1,50']]);
        Employee::query()->where('company_id', $companyId)->findOrFail($data['employee_id']);
        $page = $this->issuedDocuments($companyId)->where('documentable_id', $data['employee_id'])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (Document $document): array => $this->present($document))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, string $document): JsonResponse
    {
        $companyId = $this->authorize($request, 'employee.documents.admin.view');

        return response()->json($this->present($this->issuedDocuments($companyId)->findOrFail($document)));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'employee.documents.issue');
        $data = $request->validate([
            'employee_id' => ['required', 'uuid'],
            'file' => ['required', File::types(config('platform.document_mimes'))->max(config('platform.document_max_kilobytes')), 'extensions:'.implode(',', config('platform.document_mimes'))],
        ]);
        $employee = Employee::query()->where('company_id', $companyId)->findOrFail($data['employee_id']);
        $file = $request->file('file');
        $key = (string) $request->header('Idempotency-Key');
        if (mb_strlen($key) < 8 || mb_strlen($key) > 200) {
            throw new PlatformException('EMPLOYEE_DOCUMENT_KEY_REQUIRED', 'An Idempotency-Key of 8 to 200 characters is required.', 422);
        }
        $keyHash = hash('sha256', $key);
        $payloadHash = hash('sha256', json_encode([
            'employee_id' => $employee->id, 'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath()),
        ], JSON_THROW_ON_ERROR));
        $newDocument = null;
        try {
            $document = DB::transaction(function () use ($request, $companyId, $employee, $file, $keyHash, $payloadHash, &$newDocument): Document {
                Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
                $prior = Document::query()->where('company_id', $companyId)->where('uploaded_by', $request->user()->id)
                    ->where('employee_upload_request_key_hash', $keyHash)->first();
                if ($prior !== null) {
                    if (! hash_equals((string) $prior->employee_upload_payload_hash, $payloadHash)
                        || $prior->documentable_type !== $employee->getMorphClass() || $prior->documentable_id !== $employee->id
                        || $prior->category !== 'employee_issued') {
                        throw new PlatformException('EMPLOYEE_DOCUMENT_IDEMPOTENCY_CONFLICT', 'This idempotency key was used for different content.', 409);
                    }

                    return $prior;
                }
                $created = $this->documents->store($companyId, $request->user(), 'employee', $employee->id, 'employee_issued', $file);
                $newDocument = $created;
                $created->forceFill(['employee_upload_request_key_hash' => $keyHash, 'employee_upload_payload_hash' => $payloadHash])->save();
                $this->audit->record($request, $request->user(), $companyId, 'employee_document_issued_unreleased', 'employee_documents', $created, null, ['document_id' => $created->id, 'employee_id' => $employee->id]);

                return $created;
            });
        } catch (\Throwable $exception) {
            if ($newDocument !== null) {
                Storage::disk($newDocument->storage_disk)->delete($newDocument->storage_key);
            }
            throw $exception;
        }

        return response()->json($this->present($document), $document->wasRecentlyCreated ? 201 : 200);
    }

    public function release(Request $request, string $document): JsonResponse
    {
        $companyId = $this->authorize($request, 'employee.documents.release');
        $released = DB::transaction(function () use ($request, $companyId, $document): Document {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $model = $this->issuedDocuments($companyId)->lockForUpdate()->findOrFail($document);
            if ($model->employee_released_at !== null) {
                return $model;
            }
            $model->forceFill(['employee_released_at' => now(), 'employee_released_by' => $request->user()->id])->save();
            $this->audit->record($request, $request->user(), $companyId, 'employee_document_released', 'employee_documents', $model, null, ['document_id' => $model->id, 'employee_id' => $model->documentable_id]);
            $recipientId = CompanyUser::query()->where('company_id', $companyId)->where('employee_id', $model->documentable_id)
                ->where('is_active', true)->value('user_id');
            if ($recipientId !== null && (int) $recipientId !== $request->user()->id) {
                $this->notifications->createInApp($companyId, (int) $recipientId, 'employee.document.released', 'Document available', 'A document is available in My Documents.', ['document_id' => $model->id], '/employee/documents');
            }

            return $model;
        });

        return response()->json($this->present($released));
    }

    public function download(Request $request, string $document): StreamedResponse
    {
        $companyId = $this->authorize($request, 'employee.documents.admin.view');
        $model = $this->issuedDocuments($companyId)->findOrFail($document);
        if (! Storage::disk($model->storage_disk)->exists($model->storage_key)) {
            throw new PlatformException('FILE_NOT_FOUND', 'The stored document is unavailable.', 404);
        }

        return Storage::disk($model->storage_disk)->download($model->storage_key, $model->original_filename, ['Content-Type' => $model->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    private function authorize(Request $request, string $permission): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->entitlements->enforceRequest($companyId, 'api/v1/payroll');
        $this->access->authorize($request->user(), $companyId, $permission);

        return $companyId;
    }

    private function issuedDocuments(string $companyId): Builder
    {
        return Document::query()->where('company_id', $companyId)->where('documentable_type', (new Employee)->getMorphClass())
            ->where('category', 'employee_issued');
    }

    /** @return array<string, mixed> */
    private function present(Document $document): array
    {
        return ['id' => $document->id, 'employee_id' => $document->documentable_id,
            'category' => 'ISSUED', 'original_filename' => $document->original_filename,
            'mime_type' => $document->mime_type, 'size_bytes' => $document->size_bytes,
            'checksum_sha256' => $document->checksum_sha256,
            'released_at' => $document->employee_released_at?->toIso8601String(),
            'released_by' => $document->employee_released_by,
            'created_at' => $document->created_at?->toIso8601String()];
    }
}
