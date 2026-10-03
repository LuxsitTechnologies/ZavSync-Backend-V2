<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeAssetRequest;
use App\Models\EmployeeAssetRequestEvent;
use App\Services\AuditService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\NotificationService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeAssetRequestController extends Controller
{
    public function __construct(
        private readonly PlatformAccessService $access,
        private readonly EntitlementService $entitlements,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
    ) {}

    public function employeeIndex(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->employeeAccess($request, 'employee.assets.view');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $page = $this->owned($companyId)->where('employee_id', $employee->id)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (EmployeeAssetRequest $assetRequest): array => $this->present($assetRequest))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function employeeShow(Request $request, string $assetRequest): JsonResponse
    {
        [$companyId, $employee] = $this->employeeAccess($request, 'employee.assets.view');

        return response()->json($this->present($this->owned($companyId)->where('employee_id', $employee->id)->findOrFail($assetRequest), true));
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'assets.view');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50'],
            'status' => ['sometimes', Rule::in(['PENDING', 'APPROVED', 'REJECTED'])], 'employee_id' => ['sometimes', 'uuid']]);
        $query = $this->owned($companyId);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (isset($data['employee_id'])) {
            Employee::query()->where('company_id', $companyId)->findOrFail($data['employee_id']);
            $query->where('employee_id', $data['employee_id']);
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (EmployeeAssetRequest $assetRequest): array => $this->present($assetRequest))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function adminShow(Request $request, string $assetRequest): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'assets.view');

        return response()->json($this->present($this->owned($companyId)->findOrFail($assetRequest), true));
    }

    public function store(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->employeeAccess($request, 'employee.assets.request', true);
        $data = $request->validate(['type' => ['required', Rule::in(['NEW_EQUIPMENT'])],
            'item_description' => ['required', 'string', 'max:1000'], 'reason' => ['required', 'string', 'max:2000']]);
        $keyHash = $this->keyHash($request);
        $payloadHash = hash('sha256', json_encode([$data['type'], $data['item_description'], $data['reason']], JSON_THROW_ON_ERROR));
        $model = DB::transaction(function () use ($request, $companyId, $employee, $data, $keyHash, $payloadHash): EmployeeAssetRequest {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $prior = $this->owned($companyId)->where('employee_id', $employee->id)->where('create_request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                if (! hash_equals((string) $prior->create_payload_hash, $payloadHash)) {
                    throw new PlatformException('ASSET_REQUEST_IDEMPOTENCY_CONFLICT', 'This idempotency key was used for a different request.', 409);
                }

                return $prior;
            }
            $created = EmployeeAssetRequest::query()->create(['company_id' => $companyId, 'employee_id' => $employee->id,
                'type' => $data['type'], 'item_description' => $data['item_description'], 'reason' => $data['reason'],
                'created_by' => $request->user()->id]);
            $created->forceFill(['status' => 'PENDING', 'version' => 1,
                'create_request_key_hash' => $keyHash, 'create_payload_hash' => $payloadHash])->save();
            EmployeeAssetRequestEvent::query()->create(['company_id' => $companyId, 'employee_asset_request_id' => $created->id,
                'event_type' => 'REQUESTED', 'request_version' => 1, 'actor_id' => $request->user()->id, 'occurred_at' => now()]);
            $this->audit->record($request, $request->user(), $companyId, 'employee_asset_request_created', 'employee_assets', $created,
                null, ['request_id' => $created->id, 'status' => 'PENDING']);

            return $created;
        });

        return response()->json($this->present($model, true), $model->wasRecentlyCreated ? 201 : 200);
    }

    public function approve(Request $request, string $assetRequest): JsonResponse
    {
        return $this->decide($request, $assetRequest, 'APPROVED');
    }

    public function reject(Request $request, string $assetRequest): JsonResponse
    {
        return $this->decide($request, $assetRequest, 'REJECTED');
    }

    private function decide(Request $request, string $assetRequest, string $status): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'assets.decide');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'],
            'reason' => [$status === 'REJECTED' ? 'required' : 'nullable', 'string', 'max:2000']]);
        $model = DB::transaction(function () use ($request, $companyId, $assetRequest, $status, $data): EmployeeAssetRequest {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = $this->owned($companyId)->lockForUpdate()->findOrFail($assetRequest);
            $membership = CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)
                ->where('is_active', true)->firstOrFail();
            if ($membership->employee_id === $current->employee_id) {
                throw new PlatformException('ASSET_REQUEST_SELF_DECISION_FORBIDDEN', 'An employee cannot decide their own request.', 403);
            }
            if ($current->status === $status) {
                return $current;
            }
            if ($current->status !== 'PENDING') {
                throw new PlatformException('ASSET_REQUEST_ALREADY_DECIDED', 'This request already has a different decision.', 409);
            }
            if ($current->version !== (int) $data['version']) {
                throw new PlatformException('ASSET_REQUEST_VERSION_STALE', 'This request changed; refresh before deciding.', 409);
            }
            if ($current->version >= 4_294_967_295) {
                throw new PlatformException('ASSET_REQUEST_VERSION_EXHAUSTED', 'This request can no longer be changed.', 409);
            }
            $current->forceFill(['status' => $status, 'version' => $current->version + 1,
                'decision_reason' => $data['reason'] ?? null, 'decided_at' => now(), 'decided_by' => $request->user()->id])->save();
            EmployeeAssetRequestEvent::query()->create(['company_id' => $companyId, 'employee_asset_request_id' => $current->id,
                'event_type' => $status, 'request_version' => $current->version,
                'reason' => $data['reason'] ?? null, 'actor_id' => $request->user()->id, 'occurred_at' => now()]);
            $this->audit->record($request, $request->user(), $companyId, 'employee_asset_request_decided', 'employee_assets', $current,
                ['status' => 'PENDING'], ['status' => $status, 'version' => $current->version]);
            $recipient = CompanyUser::query()->where('company_id', $companyId)->where('employee_id', $current->employee_id)
                ->where('is_active', true)->value('user_id');
            if ($recipient !== null && (int) $recipient !== $request->user()->id) {
                $this->notifications->createInApp($companyId, (int) $recipient, 'employee.asset_request.decided',
                    'Equipment request updated', 'Your equipment request was reviewed.', ['request_id' => $current->id], '/employee/assets');
            }

            return $current;
        });

        return response()->json($this->present($model, true));
    }

    /** @return array{string, Employee} */
    private function employeeAccess(Request $request, string $permission, bool $write = false): array
    {
        $companyId = $this->adminAccess($request, $permission);
        $membership = CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)
            ->where('is_active', true)->firstOrFail();
        if ($membership->employee_id === null) {
            throw new PlatformException('EMPLOYEE_IDENTITY_NOT_LINKED', 'No employee identity is linked to this company membership.', 409);
        }
        $employee = Employee::query()->where('company_id', $companyId)->findOrFail($membership->employee_id);
        if ($write && in_array($employee->status, ['resigned', 'terminated'], true)) {
            throw new PlatformException('EMPLOYEE_ASSET_REQUEST_INACTIVE', 'Former employees cannot submit equipment requests.', 403);
        }

        return [$companyId, $employee];
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
        return EmployeeAssetRequest::query()->where('company_id', $companyId);
    }

    private function keyHash(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        if (mb_strlen($key) < 8 || mb_strlen($key) > 200) {
            throw new PlatformException('ASSET_REQUEST_KEY_REQUIRED', 'An Idempotency-Key of 8 to 200 characters is required.', 422);
        }

        return hash('sha256', $key);
    }

    /** @return array<string, mixed> */
    private function present(EmployeeAssetRequest $request, bool $includeEvents = false): array
    {
        return ['id' => $request->id, 'employee_id' => $request->employee_id, 'type' => $request->type,
            'item_description' => $request->item_description, 'reason' => $request->reason,
            'status' => $request->status, 'version' => $request->version,
            'decision_reason' => $request->decision_reason, 'decided_at' => $request->decided_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
            'events' => $includeEvents ? $request->events()->orderBy('request_version')->get()->map(fn (EmployeeAssetRequestEvent $event): array => [
                'id' => $event->id, 'type' => $event->event_type, 'version' => $event->request_version,
                'reason' => $event->reason, 'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->all() : null];
    }
}
