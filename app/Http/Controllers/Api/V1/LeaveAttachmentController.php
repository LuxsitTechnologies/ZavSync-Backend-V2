<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Document;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\AuditService;
use App\Services\Platform\DocumentService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveAttachmentController extends Controller
{
    public function __construct(private readonly DocumentService $documents, private readonly PlatformAccessService $access, private readonly EntitlementService $entitlements, private readonly AuditService $audit) {}

    public function employeeIndex(Request $request, string $leave): JsonResponse
    {
        return $this->listing($this->owned($request, $leave, false));
    }

    public function adminIndex(Request $request, string $leave): JsonResponse
    {
        return $this->listing($this->owned($request, $leave, true));
    }

    public function employeeStore(Request $request, string $leave): JsonResponse
    {
        $model = $this->owned($request, $leave, false, true);
        if ($model->status !== 'PENDING') {
            throw new PlatformException('LEAVE_ATTACHMENT_CLOSED', 'Evidence can only be added while a request is pending.', 409);
        }
        $data = $request->validate(['file' => ['required', File::types(config('platform.document_mimes'))->max(config('platform.document_max_kilobytes')), 'extensions:'.implode(',', config('platform.document_mimes'))]]);
        $document = $this->documents->store($model->company_id, $request->user(), 'leave_request', $model->id, 'leave_evidence', $request->file('file'));
        $this->audit->record($request, $request->user(), $model->company_id, 'leave_evidence_uploaded', 'leave', $model, null, ['document_id' => $document->id]);

        return response()->json($this->present($document), 201);
    }

    public function employeeDownload(Request $request, string $leave, string $document): StreamedResponse
    {
        return $this->download($this->owned($request, $leave, false), $document);
    }

    public function adminDownload(Request $request, string $leave, string $document): StreamedResponse
    {
        return $this->download($this->owned($request, $leave, true), $document);
    }

    private function owned(Request $request, string $leave, bool $admin, bool $write = false): LeaveRequest
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->entitlements->enforceRequest($companyId, 'api/v1/payroll');
        $this->access->authorize($request->user(), $companyId, $admin ? 'leave.view' : ($write ? 'employee.leave.request' : 'employee.leave.view'));
        $query = LeaveRequest::query()->where('company_id', $companyId);
        if (! $admin) {
            $membership = CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)->where('is_active', true)->firstOrFail();
            $query->where('employee_id', $membership->employee_id);
            if ($write) {
                $employee = Employee::query()->where('company_id', $companyId)->findOrFail($membership->employee_id);
                if (in_array(mb_strtolower($employee->status), ['resigned', 'terminated'], true)) {
                    throw new PlatformException('EMPLOYEE_LEAVE_INACTIVE', 'Former employees cannot add leave evidence.', 403);
                }
            }
        }

        return $query->findOrFail($leave);
    }

    private function listing(LeaveRequest $leave): JsonResponse
    {
        return response()->json(['data' => $this->query($leave)->orderBy('created_at')->get()->map(fn (Document $document): array => $this->present($document))->all()]);
    }

    private function download(LeaveRequest $leave, string $id): StreamedResponse
    {
        $document = $this->query($leave)->findOrFail($id);
        if (! Storage::disk($document->storage_disk)->exists($document->storage_key)) {
            throw new PlatformException('FILE_NOT_FOUND', 'The stored document is unavailable.', 404);
        }

        return Storage::disk($document->storage_disk)->download($document->storage_key, $document->original_filename, ['Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    private function query(LeaveRequest $leave): Builder
    {
        return Document::query()->where('company_id', $leave->company_id)
            ->where('documentable_type', $leave->getMorphClass())->where('documentable_id', $leave->id)->where('category', 'leave_evidence');
    }

    /** @return array<string, mixed> */
    private function present(Document $document): array
    {
        return $document->only(['id', 'original_filename', 'mime_type', 'size_bytes', 'checksum_sha256', 'created_at']);
    }
}
