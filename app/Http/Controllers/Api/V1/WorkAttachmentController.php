<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\EmployeeTask;
use App\Models\EmployeeTicket;
use App\Services\AuditService;
use App\Services\Platform\DocumentService;
use App\Services\Work\EmployeeWorkAccess;
use App\Services\Work\EmployeeWorkService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkAttachmentController extends Controller
{
    public function __construct(private readonly EmployeeWorkAccess $access, private readonly EmployeeWorkService $work, private readonly DocumentService $documents, private readonly AuditService $audit) {}

    public function employeeTaskIndex(Request $request, string $task): JsonResponse
    {
        return $this->listing($this->parent($request, 'task', $task, false));
    }

    public function employeeTaskStore(Request $request, string $task): JsonResponse
    {
        return $this->store($request, 'task', $task, false);
    }

    public function employeeTaskDownload(Request $request, string $task, string $document): StreamedResponse
    {
        return $this->download($this->parent($request, 'task', $task, false), $document);
    }

    public function adminTaskIndex(Request $request, string $task): JsonResponse
    {
        return $this->listing($this->parent($request, 'task', $task, true));
    }

    public function adminTaskStore(Request $request, string $task): JsonResponse
    {
        return $this->store($request, 'task', $task, true);
    }

    public function adminTaskDownload(Request $request, string $task, string $document): StreamedResponse
    {
        return $this->download($this->parent($request, 'task', $task, true), $document);
    }

    public function employeeTicketIndex(Request $request, string $ticket): JsonResponse
    {
        return $this->listing($this->parent($request, 'ticket', $ticket, false));
    }

    public function employeeTicketStore(Request $request, string $ticket): JsonResponse
    {
        return $this->store($request, 'ticket', $ticket, false);
    }

    public function employeeTicketDownload(Request $request, string $ticket, string $document): StreamedResponse
    {
        return $this->download($this->parent($request, 'ticket', $ticket, false), $document);
    }

    public function adminTicketIndex(Request $request, string $ticket): JsonResponse
    {
        return $this->listing($this->parent($request, 'ticket', $ticket, true));
    }

    public function adminTicketStore(Request $request, string $ticket): JsonResponse
    {
        return $this->store($request, 'ticket', $ticket, true);
    }

    public function adminTicketDownload(Request $request, string $ticket, string $document): StreamedResponse
    {
        return $this->download($this->parent($request, 'ticket', $ticket, true), $document);
    }

    private function parent(Request $request, string $type, string $id, bool $admin, bool $write = false): EmployeeTask|EmployeeTicket
    {
        $permission = $type === 'task'
            ? ($admin ? ($write ? 'tasks.manage' : 'tasks.view') : ($write ? 'employee.tasks.comment' : 'employee.tasks.view'))
            : ($admin ? ($write ? 'tickets.manage' : 'tickets.view') : ($write ? 'employee.tickets.comment' : 'employee.tickets.view'));
        if ($admin) {
            $companyId = $this->access->company($request, $permission);
            $employeeId = null;
        } else {
            [$companyId, $employee] = $this->access->employee($request, $permission, $write);
            $employeeId = $employee->id;
        }

        return $type === 'task' ? $this->access->task($companyId, $id, $employeeId) : $this->access->ticket($companyId, $id, $employeeId);
    }

    private function listing(EmployeeTask|EmployeeTicket $parent): JsonResponse
    {
        return response()->json(['data' => $this->query($parent)->orderBy('created_at')->orderBy('id')->get()->map(fn (Document $document): array => $this->present($document))->all()]);
    }

    private function store(Request $request, string $type, string $id, bool $admin): JsonResponse
    {
        $parent = $this->parent($request, $type, $id, $admin, true);
        $request->validate(['file' => ['required', File::types(config('platform.document_mimes'))->max(config('platform.document_max_kilobytes')), 'extensions:'.implode(',', config('platform.document_mimes'))]]);
        $file = $request->file('file');
        $keyHash = $this->work->keyHash((string) $request->header('Idempotency-Key'));
        $payloadHash = $this->work->payloadHash(['type' => $type, 'parent_id' => $id, 'filename' => $file->getClientOriginalName(), 'size' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath())]);
        $newDocument = null;
        try {
            $document = DB::transaction(function () use ($request, $parent, $type, $id, $keyHash, $payloadHash, $file, &$newDocument): Document {
                $locked = $parent->newQuery()->where('company_id', $parent->company_id)->lockForUpdate()->findOrFail($parent->id);
                $prior = Document::query()->where('company_id', $locked->company_id)->where('work_request_key_hash', $keyHash)->first();
                if ($prior !== null) {
                    if (! hash_equals((string) $prior->work_payload_hash, $payloadHash) || $prior->documentable_type !== $locked->getMorphClass() || $prior->documentable_id !== $locked->id) {
                        throw new PlatformException('WORK_IDEMPOTENCY_CONFLICT', 'This idempotency key was used for different content.', 409);
                    }

                    return $prior;
                }
                if (! in_array($locked->status, $type === 'task' ? ['ASSIGNED', 'IN_PROGRESS'] : ['OPEN', 'IN_PROGRESS', 'RESOLVED'], true)) {
                    throw new PlatformException('WORK_ATTACHMENT_CLOSED', 'This work item is closed to attachments.', 409);
                }
                $document = $this->documents->store($locked->company_id, $request->user(), $type === 'task' ? 'employee_task' : 'employee_ticket', $id, $type.'_attachment', $file);
                $newDocument = $document;
                $document->forceFill(['work_request_key_hash' => $keyHash, 'work_payload_hash' => $payloadHash])->save();
                $this->audit->record($request, $request->user(), $locked->company_id, 'employee_'.$type.'_attachment_uploaded', 'employee_work', $locked, null, ['document_id' => $document->id]);

                return $document;
            });
        } catch (\Throwable $exception) {
            if ($newDocument !== null) {
                Storage::disk($newDocument->storage_disk)->delete($newDocument->storage_key);
            }
            throw $exception;
        }

        return response()->json($this->present($document), $document->wasRecentlyCreated ? 201 : 200);
    }

    private function download(EmployeeTask|EmployeeTicket $parent, string $id): StreamedResponse
    {
        $document = $this->query($parent)->findOrFail($id);
        if (! Storage::disk($document->storage_disk)->exists($document->storage_key)) {
            throw new PlatformException('FILE_NOT_FOUND', 'The stored document is unavailable.', 404);
        }

        return Storage::disk($document->storage_disk)->download($document->storage_key, $document->original_filename, ['Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    private function query(EmployeeTask|EmployeeTicket $parent): Builder
    {
        return Document::query()->where('company_id', $parent->company_id)->where('documentable_type', $parent->getMorphClass())
            ->where('documentable_id', $parent->id)->where('category', $parent instanceof EmployeeTask ? 'task_attachment' : 'ticket_attachment');
    }

    /** @return array<string, mixed> */
    private function present(Document $document): array
    {
        return $document->only(['id', 'original_filename', 'mime_type', 'size_bytes', 'checksum_sha256', 'created_at']);
    }
}
