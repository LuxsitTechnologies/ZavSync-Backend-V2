<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\LeaveEntitlement;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\AuditService;
use App\Services\Leave\LeaveService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveAdminController extends Controller
{
    public function __construct(private readonly LeaveService $leaves, private readonly PlatformAccessService $access, private readonly EntitlementService $entitlements, private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'leave.view');
        $data = $request->validate(['employee_id' => ['sometimes', 'uuid'], 'status' => ['sometimes', 'in:PENDING,APPROVED,REJECTED,CANCELLATION_PENDING,CANCELLED'], 'per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $query = LeaveRequest::query()->where('company_id', $companyId);
        foreach (['employee_id', 'status'] as $filter) {
            if (isset($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);
        $viewerEmployeeId = $this->viewerEmployeeId($request, $companyId);

        return response()->json(['data' => $page->getCollection()->map(fn (LeaveRequest $leave): array => $this->leaves->presentForAdmin($leave, $viewerEmployeeId))->all(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, string $leave): JsonResponse
    {
        $companyId = $this->authorize($request, 'leave.view');

        return response()->json($this->leaves->presentForAdmin(LeaveRequest::query()->where('company_id', $companyId)->findOrFail($leave), $this->viewerEmployeeId($request, $companyId), true));
    }

    public function types(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'leave.view');

        return response()->json(['data' => LeaveType::query()->where('company_id', $companyId)->orderBy('name')->get(['id', 'name', 'is_paid', 'is_active'])]);
    }

    public function storeType(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'leave.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'is_paid' => ['required', 'boolean']]);
        $type = LeaveType::query()->create(['company_id' => $companyId, ...$data]);
        $this->audit->record($request, $request->user(), $companyId, 'leave_type_created', 'leave', $type, null, $data);

        return response()->json($type->only(['id', 'name', 'is_paid', 'is_active']), 201);
    }

    public function updateType(Request $request, string $type): JsonResponse
    {
        $companyId = $this->authorize($request, 'leave.manage');
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'is_active' => ['sometimes', 'boolean']]);
        $model = LeaveType::query()->where('company_id', $companyId)->findOrFail($type);
        $before = $model->only(['name', 'is_active']);
        $model->fill($data)->save();
        $this->audit->record($request, $request->user(), $companyId, 'leave_type_updated', 'leave', $model, $before, $data);

        return response()->json($model->only(['id', 'name', 'is_paid', 'is_active']));
    }

    public function entitlements(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'leave.view');
        $data = $request->validate(['employee_id' => ['required', 'uuid'], 'year' => ['sometimes', 'integer', 'between:2000,2200']]);
        Employee::query()->where('company_id', $companyId)->findOrFail($data['employee_id']);
        $rows = LeaveEntitlement::query()->where('company_id', $companyId)->where('employee_id', $data['employee_id'])->where('year', $data['year'] ?? now()->year)->with('type')->get();

        return response()->json(['data' => $rows->map(fn (LeaveEntitlement $row): array => ['id' => $row->id, 'leave_type_id' => $row->leave_type_id, 'type_name' => $row->type->name, 'year' => $row->year, ...$this->leaves->balance($row)])->all()]);
    }

    public function storeEntitlement(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'leave.manage');
        $data = $request->validate(['employee_id' => ['required', 'uuid'], 'leave_type_id' => ['required', 'uuid'], 'year' => ['required', 'integer', 'between:2000,2200'], 'allocated_units' => ['required', 'integer', 'between:0,10000']]);
        $row = DB::transaction(function () use ($request, $companyId, $data): LeaveEntitlement {
            Employee::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($data['employee_id']);
            $type = LeaveType::query()->where('company_id', $companyId)->findOrFail($data['leave_type_id']);
            if (! $type->is_paid) {
                abort(422, 'Unpaid leave does not require entitlement.');
            }
            $prior = LeaveEntitlement::query()->where('company_id', $companyId)->where('employee_id', $data['employee_id'])
                ->where('leave_type_id', $type->id)->where('year', $data['year'])->first();
            if ($prior !== null) {
                if ($prior->allocated_units !== $data['allocated_units']) {
                    throw new PlatformException('LEAVE_ENTITLEMENT_EXISTS', 'Use an audited adjustment to change an existing entitlement.', 409);
                }

                return $prior;
            }
            $row = LeaveEntitlement::query()->create(['company_id' => $companyId, ...$data, 'created_by' => $request->user()->id]);
            $this->audit->record($request, $request->user(), $companyId, 'leave_entitlement_created', 'leave', $row, null, $data);

            return $row;
        }, 3);

        return response()->json(['id' => $row->id, ...$this->leaves->balance($row)], $row->wasRecentlyCreated ? 201 : 200);
    }

    public function adjust(Request $request, string $entitlement): JsonResponse
    {
        $companyId = $this->authorize($request, 'leave.manage');
        $data = $request->validate(['delta_units' => ['required', 'integer', 'between:-10000,10000', 'not_in:0'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $row = LeaveEntitlement::query()->where('company_id', $companyId)->findOrFail($entitlement);
        $adjustment = $this->leaves->adjust($request, $row, $data['delta_units'], $data['reason'], (string) $request->header('Idempotency-Key'));

        return response()->json(['id' => $adjustment->id, 'delta_units' => $adjustment->delta_units, ...$this->leaves->balance($row)], $adjustment->wasRecentlyCreated ? 201 : 200);
    }

    public function approve(Request $request, string $leave): JsonResponse
    {
        return $this->decide($request, $leave, 'APPROVE');
    }

    public function reject(Request $request, string $leave): JsonResponse
    {
        return $this->decide($request, $leave, 'REJECT');
    }

    public function approveCancellation(Request $request, string $leave): JsonResponse
    {
        return $this->decide($request, $leave, 'APPROVE_CANCELLATION');
    }

    public function rejectCancellation(Request $request, string $leave): JsonResponse
    {
        return $this->decide($request, $leave, 'REJECT_CANCELLATION');
    }

    private function decide(Request $request, string $leave, string $action): JsonResponse
    {
        $companyId = $this->authorize($request, 'leave.approve');
        $data = $request->validate(['reason' => [in_array($action, ['REJECT', 'REJECT_CANCELLATION'], true) ? 'required' : 'sometimes', 'string', 'min:5', 'max:2000']]);
        $item = $this->leaves->transition($request, $companyId, $leave, $action, $data['reason'] ?? null);

        return response()->json($this->leaves->presentForAdmin($item, $this->viewerEmployeeId($request, $companyId), true));
    }

    private function viewerEmployeeId(Request $request, string $companyId): ?string
    {
        return CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)
            ->where('is_active', true)->value('employee_id');
    }

    private function authorize(Request $request, string $permission): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->entitlements->enforceRequest($companyId, 'api/v1/payroll');
        $this->access->authorize($request->user(), $companyId, $permission);

        return $companyId;
    }
}
