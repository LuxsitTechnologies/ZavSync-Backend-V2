<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\CompanyAnnouncement;
use App\Models\Document;
use App\Models\EmployeeExpenseClaim;
use App\Models\EmployeeTask;
use App\Models\EmployeeTicket;
use App\Models\LeaveRequest;
use App\Services\AuditService;
use App\Services\Platform\DocumentService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents, private readonly PlatformAccessService $access, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.documents.view');
        $query = Document::query()->where('company_id', $companyId);
        $query->whereNotIn('documentable_type', $this->restrictedMorphTypes())->whereNotIn('category', $this->restrictedCategories());
        if ($request->filled('documentable_type') && $request->filled('documentable_id')) {
            abort_if(in_array($request->string('documentable_type')->toString(), ['leave_request', 'employee_task', 'employee_ticket', 'announcement', 'expense_claim'], true), 404);
            $entity = $this->documents->resolveOwnedEntity($companyId, $request->string('documentable_type')->toString(), $request->string('documentable_id')->toString());
            $query->where('documentable_type', $entity->getMorphClass())->where('documentable_id', (string) $entity->getKey());
        }

        return response()->json($query->latest()->paginate(30));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.documents.manage');
        $data = $request->validate([
            'documentable_type' => ['required', 'string'], 'documentable_id' => ['required', 'string', 'max:64'],
            'category' => ['nullable', 'string', 'max:60'],
            'file' => ['required', File::types(config('platform.document_mimes'))->max(config('platform.document_max_kilobytes')), 'extensions:'.implode(',', config('platform.document_mimes'))],
        ]);
        abort_if(in_array($data['documentable_type'], ['leave_request', 'employee_task', 'employee_ticket', 'announcement', 'expense_claim'], true), 404);
        abort_if(in_array($data['category'] ?? null, $this->restrictedCategories(), true), 404);
        $document = $this->documents->store($companyId, $request->user(), $data['documentable_type'], $data['documentable_id'], $data['category'] ?? 'general', $request->file('file'));
        $this->audit->record($request, $request->user(), $companyId, 'document_uploaded', 'platform', $document, null, $document->toArray());

        return response()->json($document, 201);
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        $this->owned($request, $document);
        abort_if(in_array($document->documentable_type, $this->restrictedMorphTypes(), true), 404);
        abort_if(in_array($document->category, $this->restrictedCategories(), true), 404);
        $this->access->authorize($request->user(), $this->companyId($request), 'platform.documents.view');
        if (! Storage::disk($document->storage_disk)->exists($document->storage_key)) {
            throw new PlatformException('FILE_NOT_FOUND', 'The stored document is unavailable.', 404);
        }

        return Storage::disk($document->storage_disk)->download($document->storage_key, $document->original_filename, ['Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, Document $document): JsonResponse
    {
        $this->owned($request, $document);
        abort_if(in_array($document->documentable_type, $this->restrictedMorphTypes(), true), 404);
        abort_if(in_array($document->category, $this->restrictedCategories(), true), 404);
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.documents.manage');
        $old = $document->toArray();
        $this->audit->record($request, $request->user(), $companyId, 'document_deleted', 'platform', $document, $old, null);
        $this->documents->delete($document);

        return response()->json(['message' => 'Document deleted.']);
    }

    private function owned(Request $request, Document $document): void
    {
        abort_unless($document->company_id === $this->companyId($request), 404);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }

    /** @return list<string> */
    private function restrictedMorphTypes(): array
    {
        return [(new LeaveRequest)->getMorphClass(), (new EmployeeTask)->getMorphClass(), (new EmployeeTicket)->getMorphClass(), (new CompanyAnnouncement)->getMorphClass(), (new EmployeeExpenseClaim)->getMorphClass()];
    }

    /** @return list<string> */
    private function restrictedCategories(): array
    {
        return ['employee_personal', 'employee_issued'];
    }
}
