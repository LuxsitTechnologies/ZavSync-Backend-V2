<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyAnnouncement;
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
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnnouncementController extends Controller
{
    public function __construct(
        private readonly PlatformAccessService $access,
        private readonly EntitlementService $entitlements,
        private readonly DocumentService $documents,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
    ) {}

    public function employeeIndex(Request $request): JsonResponse
    {
        $companyId = $this->employeeAccess($request);
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $page = $this->published($companyId)->orderByDesc('published_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (CompanyAnnouncement $announcement): array => $this->present($announcement))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function employeeShow(Request $request, string $announcement): JsonResponse
    {
        $companyId = $this->employeeAccess($request);

        return response()->json($this->present($this->published($companyId)->findOrFail($announcement)));
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'announcements.view');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50'],
            'status' => ['sometimes', Rule::in(['DRAFT', 'PUBLISHED'])]]);
        $query = CompanyAnnouncement::query()->where('company_id', $companyId);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (CompanyAnnouncement $announcement): array => $this->present($announcement))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function adminShow(Request $request, string $announcement): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'announcements.view');

        return response()->json($this->present($this->owned($companyId)->findOrFail($announcement)));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'announcements.manage');
        $data = $request->validate($this->announcementRules());
        $keyHash = $this->keyHash($request);
        $payloadHash = hash('sha256', json_encode([$data['title'], $data['description'], $data['priority'], $data['expires_at'] ?? null], JSON_THROW_ON_ERROR));
        $announcement = DB::transaction(function () use ($request, $companyId, $data, $keyHash, $payloadHash): CompanyAnnouncement {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $prior = $this->owned($companyId)->where('created_by', $request->user()->id)
                ->where('create_request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                if (! hash_equals((string) $prior->create_payload_hash, $payloadHash)) {
                    throw new PlatformException('ANNOUNCEMENT_IDEMPOTENCY_CONFLICT', 'This idempotency key was used for different announcement content.', 409);
                }

                return $prior;
            }
            $created = CompanyAnnouncement::query()->create([
                'company_id' => $companyId, 'title' => $data['title'], 'description' => $data['description'],
                'priority' => $data['priority'], 'created_by' => $request->user()->id,
            ]);
            $created->forceFill(['status' => 'DRAFT', 'version' => 1, 'expires_at' => $data['expires_at'] ?? null,
                'create_request_key_hash' => $keyHash, 'create_payload_hash' => $payloadHash])->save();
            $this->audit->record($request, $request->user(), $companyId, 'announcement_draft_created', 'announcements', $created,
                null, ['announcement_id' => $created->id, 'version' => 1]);

            return $created;
        });

        return response()->json($this->present($announcement), $announcement->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, string $announcement): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'announcements.manage');
        $data = $request->validate([...$this->announcementRules(), 'version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($request, $companyId, $announcement, $data): CompanyAnnouncement {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = $this->owned($companyId)->lockForUpdate()->findOrFail($announcement);
            if ($current->status !== 'DRAFT') {
                throw new PlatformException('ANNOUNCEMENT_ALREADY_PUBLISHED', 'Published announcements are immutable.', 409);
            }
            if ($current->version !== (int) $data['version']) {
                throw new PlatformException('ANNOUNCEMENT_VERSION_STALE', 'The announcement changed; refresh before editing.', 409);
            }
            if ($current->version >= 4_294_967_295) {
                throw new PlatformException('ANNOUNCEMENT_VERSION_EXHAUSTED', 'The announcement can no longer be updated.', 409);
            }
            $current->forceFill(['title' => $data['title'], 'description' => $data['description'],
                'priority' => $data['priority'], 'expires_at' => $data['expires_at'] ?? null,
                'version' => $current->version + 1, 'updated_by' => $request->user()->id])->save();
            $this->audit->record($request, $request->user(), $companyId, 'announcement_draft_updated', 'announcements', $current,
                ['version' => $data['version']], ['version' => $current->version]);

            return $current;
        });

        return response()->json($this->present($model));
    }

    public function publish(Request $request, string $announcement): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'announcements.publish');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($request, $companyId, $announcement, $data): CompanyAnnouncement {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = $this->owned($companyId)->lockForUpdate()->findOrFail($announcement);
            if ($current->status === 'PUBLISHED') {
                return $current;
            }
            if ($current->version !== (int) $data['version']) {
                throw new PlatformException('ANNOUNCEMENT_VERSION_STALE', 'The announcement changed; refresh before publishing.', 409);
            }
            if ($current->expires_at !== null && $current->expires_at->lessThanOrEqualTo(now())) {
                throw new PlatformException('ANNOUNCEMENT_EXPIRY_INVALID', 'An announcement cannot be published after its expiry.', 422);
            }
            if ($current->version >= 4_294_967_295) {
                throw new PlatformException('ANNOUNCEMENT_VERSION_EXHAUSTED', 'The announcement can no longer be published.', 409);
            }
            $current->forceFill(['status' => 'PUBLISHED', 'published_at' => now(),
                'published_by' => $request->user()->id, 'version' => $current->version + 1])->save();
            $this->audit->record($request, $request->user(), $companyId, 'announcement_published', 'announcements', $current,
                ['status' => 'DRAFT'], ['status' => 'PUBLISHED']);
            CompanyUser::query()->with('user')->where('company_id', $companyId)->where('is_active', true)
                ->whereNotNull('employee_id')->orderBy('id')->chunk(100, function ($memberships) use ($request, $companyId, $current): void {
                    foreach ($memberships as $membership) {
                        if ($membership->user_id === $request->user()->id
                            || ! $membership->user?->hasCompanyPermission($companyId, 'employee.announcements.view')) {
                            continue;
                        }
                        $this->notifications->createInApp($companyId, $membership->user_id, 'employee.announcement.published',
                            'New company announcement', $current->title, ['announcement_id' => $current->id], '/employee/announcements');
                    }
                });

            return $current;
        });

        return response()->json($this->present($model));
    }

    public function uploadAttachment(Request $request, string $announcement): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'announcements.manage');
        $data = $request->validate(['file' => ['required', File::types(config('platform.document_mimes'))->max(config('platform.document_max_kilobytes')), 'extensions:'.implode(',', config('platform.document_mimes'))],
            'version' => ['required', 'integer', 'min:1']]);
        $file = $request->file('file');
        $keyHash = $this->keyHash($request);
        $payloadHash = hash('sha256', json_encode([$file->getClientOriginalName(), $file->getSize(), hash_file('sha256', $file->getRealPath())], JSON_THROW_ON_ERROR));
        $newDocument = null;
        try {
            $model = DB::transaction(function () use ($request, $companyId, $announcement, $file, $keyHash, $payloadHash, $data, &$newDocument): CompanyAnnouncement {
                Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
                $current = $this->owned($companyId)->lockForUpdate()->findOrFail($announcement);
                if ($current->attachment_document_id !== null) {
                    if ($current->attachment_request_key_hash !== $keyHash || ! hash_equals((string) $current->attachment_payload_hash, $payloadHash)) {
                        throw new PlatformException('ANNOUNCEMENT_ATTACHMENT_CONFLICT', 'This announcement already has a different attachment.', 409);
                    }

                    return $current;
                }
                if ($current->status !== 'DRAFT') {
                    throw new PlatformException('ANNOUNCEMENT_ALREADY_PUBLISHED', 'Published announcements cannot receive a new attachment.', 409);
                }
                if ($current->version !== (int) $data['version']) {
                    throw new PlatformException('ANNOUNCEMENT_VERSION_STALE', 'The announcement changed; refresh before attaching a file.', 409);
                }
                if ($current->version >= 4_294_967_295) {
                    throw new PlatformException('ANNOUNCEMENT_VERSION_EXHAUSTED', 'The announcement can no longer be updated.', 409);
                }
                $document = $this->documents->store($companyId, $request->user(), 'announcement', $current->id, 'announcement_attachment', $file);
                $newDocument = $document;
                $current->forceFill(['attachment_document_id' => $document->id,
                    'attachment_request_key_hash' => $keyHash, 'attachment_payload_hash' => $payloadHash,
                    'version' => $current->version + 1])->save();
                $this->audit->record($request, $request->user(), $companyId, 'announcement_attachment_added', 'announcements', $current,
                    null, ['announcement_id' => $current->id, 'document_id' => $document->id]);

                return $current;
            });
        } catch (\Throwable $exception) {
            if ($newDocument !== null) {
                Storage::disk($newDocument->storage_disk)->delete($newDocument->storage_key);
            }
            throw $exception;
        }

        return response()->json($this->present($model));
    }

    public function employeeAttachment(Request $request, string $announcement): StreamedResponse
    {
        $companyId = $this->employeeAccess($request);

        return $this->download($this->published($companyId)->findOrFail($announcement), $companyId);
    }

    public function adminAttachment(Request $request, string $announcement): StreamedResponse
    {
        $companyId = $this->adminAccess($request, 'announcements.view');

        return $this->download($this->owned($companyId)->findOrFail($announcement), $companyId);
    }

    private function download(CompanyAnnouncement $announcement, string $companyId): StreamedResponse
    {
        $document = Document::query()->where('company_id', $companyId)
            ->where('documentable_type', $announcement->getMorphClass())->where('documentable_id', $announcement->id)
            ->whereKey($announcement->attachment_document_id)->firstOrFail();
        if (! Storage::disk($document->storage_disk)->exists($document->storage_key)) {
            throw new PlatformException('FILE_NOT_FOUND', 'The stored document is unavailable.', 404);
        }

        return Storage::disk($document->storage_disk)->download($document->storage_key, $document->original_filename,
            ['Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    private function employeeAccess(Request $request): string
    {
        $companyId = $this->adminAccess($request, 'employee.announcements.view');
        $membership = CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)
            ->where('is_active', true)->firstOrFail();
        if ($membership->employee_id === null) {
            throw new PlatformException('EMPLOYEE_IDENTITY_NOT_LINKED', 'No employee identity is linked to this company membership.', 409);
        }
        Employee::query()->where('company_id', $companyId)->findOrFail($membership->employee_id);

        return $companyId;
    }

    private function adminAccess(Request $request, string $permission): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->entitlements->enforceRequest($companyId, 'api/v1/payroll');
        $this->access->authorize($request->user(), $companyId, $permission);

        return $companyId;
    }

    private function owned(string $companyId): Builder
    {
        return CompanyAnnouncement::query()->where('company_id', $companyId);
    }

    private function published(string $companyId): Builder
    {
        return $this->owned($companyId)->where('status', 'PUBLISHED')->where('published_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /** @return array<string, array<int, string>> */
    private function announcementRules(): array
    {
        return ['title' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:20000'],
            'priority' => ['required', Rule::in(['LOW', 'NORMAL', 'HIGH'])], 'expires_at' => ['nullable', 'date']];
    }

    private function keyHash(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        if (mb_strlen($key) < 8 || mb_strlen($key) > 200) {
            throw new PlatformException('ANNOUNCEMENT_KEY_REQUIRED', 'An Idempotency-Key of 8 to 200 characters is required.', 422);
        }

        return hash('sha256', $key);
    }

    /** @return array<string, mixed> */
    private function present(CompanyAnnouncement $announcement): array
    {
        $attachment = $announcement->attachment_document_id === null ? null : Document::query()->where('company_id', $announcement->company_id)
            ->whereKey($announcement->attachment_document_id)->first();

        return ['id' => $announcement->id, 'title' => $announcement->title,
            'description' => $announcement->description, 'priority' => $announcement->priority,
            'status' => $announcement->status, 'version' => $announcement->version,
            'published_at' => $announcement->published_at?->toIso8601String(),
            'expires_at' => $announcement->expires_at?->toIso8601String(),
            'attachment' => $attachment === null ? null : ['id' => $attachment->id,
                'original_filename' => $attachment->original_filename, 'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes],
            'created_at' => $announcement->created_at?->toIso8601String()];
    }
}
