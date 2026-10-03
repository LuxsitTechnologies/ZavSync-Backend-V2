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
use App\Services\Platform\PlatformAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly PlatformAccessService $access,
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->identity($request, 'employee.documents.view');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $page = $this->personalDocuments($companyId, $employee->id, $request->user()->id)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (Document $document): array => $this->present($document))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, string $document): JsonResponse
    {
        [$companyId, $employee] = $this->identity($request, 'employee.documents.view');
        $model = $this->personalDocuments($companyId, $employee->id, $request->user()->id)->findOrFail($document);

        return response()->json($this->present($model));
    }

    public function store(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->identity($request, 'employee.documents.upload', true);
        $request->validate(['file' => ['required', File::types(config('platform.document_mimes'))->max(config('platform.document_max_kilobytes')), 'extensions:'.implode(',', config('platform.document_mimes'))]]);
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
                        || $prior->category !== 'employee_personal') {
                        throw new PlatformException('EMPLOYEE_DOCUMENT_IDEMPOTENCY_CONFLICT', 'This idempotency key was used for different content.', 409);
                    }

                    return $prior;
                }
                $created = $this->documents->store($companyId, $request->user(), 'employee', $employee->id, 'employee_personal', $file);
                $newDocument = $created;
                $created->forceFill(['employee_upload_request_key_hash' => $keyHash, 'employee_upload_payload_hash' => $payloadHash])->save();
                $this->audit->record($request, $request->user(), $companyId, 'employee_personal_document_uploaded', 'employee_documents', $created, null, ['document_id' => $created->id]);

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

    public function download(Request $request, string $document): StreamedResponse
    {
        [$companyId, $employee] = $this->identity($request, 'employee.documents.view');
        $model = $this->personalDocuments($companyId, $employee->id, $request->user()->id)->findOrFail($document);
        if (! Storage::disk($model->storage_disk)->exists($model->storage_key)) {
            throw new PlatformException('FILE_NOT_FOUND', 'The stored document is unavailable.', 404);
        }

        return Storage::disk($model->storage_disk)->download($model->storage_key, $model->original_filename, ['Content-Type' => $model->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array{string, Employee} */
    private function identity(Request $request, string $permission, bool $write = false): array
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->entitlements->enforceRequest($companyId, 'api/v1/payroll');
        $this->access->authorize($request->user(), $companyId, $permission);
        $membership = CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)
            ->where('is_active', true)->firstOrFail();
        if ($membership->employee_id === null) {
            throw new PlatformException('EMPLOYEE_IDENTITY_NOT_LINKED', 'No employee identity is linked to this company membership.', 409);
        }
        $employee = Employee::query()->where('company_id', $companyId)->findOrFail($membership->employee_id);
        if ($write && in_array(mb_strtolower($employee->status), ['resigned', 'terminated'], true)) {
            throw new PlatformException('EMPLOYEE_DOCUMENT_INACTIVE', 'Former employees may only read personal documents.', 403);
        }

        return [$companyId, $employee];
    }

    private function personalDocuments(string $companyId, string $employeeId, int $userId): Builder
    {
        return Document::query()->where('company_id', $companyId)->where('documentable_type', (new Employee)->getMorphClass())
            ->where('documentable_id', $employeeId)->where(function (Builder $query) use ($userId): void {
                $query->where(function (Builder $personal) use ($userId): void {
                    $personal->where('uploaded_by', $userId)->where('category', 'employee_personal');
                })->orWhere(function (Builder $issued): void {
                    $issued->where('category', 'employee_issued')->whereNotNull('employee_released_at');
                });
            });
    }

    /** @return array<string, mixed> */
    private function present(Document $document): array
    {
        return ['id' => $document->id, 'category' => $document->category === 'employee_issued' ? 'ISSUED' : 'PERSONAL', 'original_filename' => $document->original_filename,
            'mime_type' => $document->mime_type, 'size_bytes' => $document->size_bytes,
            'checksum_sha256' => $document->checksum_sha256, 'created_at' => $document->created_at?->toIso8601String()];
    }
}
