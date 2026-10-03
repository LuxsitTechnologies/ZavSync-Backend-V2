<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\LeaveEntitlement;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Leave\LeaveService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeLeaveController extends Controller
{
    public function __construct(private readonly LeaveService $leaves, private readonly PlatformAccessService $access, private readonly EntitlementService $entitlements) {}

    public function summary(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->identity($request, 'employee.leave.view');
        $year = $request->validate(['year' => ['sometimes', 'integer', 'between:2000,2200']])['year'] ?? now()->year;
        $rows = LeaveEntitlement::query()->where('company_id', $companyId)->where('employee_id', $employee->id)
            ->where('year', $year)->with('type')->orderBy('id')->get();

        return response()->json(['year' => $year, 'data' => $rows->map(fn (LeaveEntitlement $row): array => [
            'leave_type_id' => $row->leave_type_id, 'type_name' => $row->type->name,
            ...$this->leaves->balance($row),
        ])->all()]);
    }

    public function types(Request $request): JsonResponse
    {
        [$companyId] = $this->identity($request, 'employee.leave.view');

        return response()->json(['data' => LeaveType::query()->where('company_id', $companyId)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'is_paid'])->map(fn (LeaveType $type): array => [
                'id' => $type->id, 'name' => $type->name, 'is_paid' => $type->is_paid,
            ])->all()]);
    }

    public function index(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->identity($request, 'employee.leave.view');
        $data = $request->validate(['status' => ['sometimes', 'in:PENDING,APPROVED,REJECTED,CANCELLATION_PENDING,CANCELLED'],
            'per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $query = LeaveRequest::query()->where('company_id', $companyId)->where('employee_id', $employee->id);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (LeaveRequest $leave): array => $this->leaves->present($leave))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, string $leave): JsonResponse
    {
        [$companyId, $employee] = $this->identity($request, 'employee.leave.view');
        $model = LeaveRequest::query()->where('company_id', $companyId)->where('employee_id', $employee->id)->findOrFail($leave);

        return response()->json($this->leaves->present($model, true));
    }

    public function store(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->identity($request, 'employee.leave.request');
        $data = $request->validate(['leave_type_id' => ['required', 'uuid'], 'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'day_portion' => ['required', 'in:FULL_DAY,FIRST_HALF,SECOND_HALF'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $leave = $this->leaves->submit($request, $companyId, $employee->id, $data, (string) $request->header('Idempotency-Key'));

        return response()->json($this->leaves->present($leave, true), $leave->wasRecentlyCreated ? 201 : 200);
    }

    public function cancel(Request $request, string $leave): JsonResponse
    {
        [$companyId, $employee] = $this->identity($request, 'employee.leave.cancel');
        $model = LeaveRequest::query()->where('company_id', $companyId)->where('employee_id', $employee->id)->findOrFail($leave);
        if ($model->status === 'CANCELLED') {
            return response()->json($this->leaves->present($model, true));
        }
        $action = match ($model->status) {
            'PENDING' => 'CANCEL_PENDING',
            'APPROVED', 'CANCELLATION_PENDING' => 'REQUEST_CANCELLATION',
            default => throw new PlatformException('LEAVE_CANCELLATION_UNAVAILABLE', 'This leave request cannot be cancelled.', 409),
        };
        $result = $this->leaves->transition($request, $companyId, $model->id, $action);

        return response()->json($this->leaves->present($result, true));
    }

    /** @return array{string, Employee} */
    private function identity(Request $request, string $permission): array
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

        return [$companyId, $employee];
    }
}
