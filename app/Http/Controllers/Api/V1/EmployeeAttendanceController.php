<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceSession;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Services\Attendance\AttendanceCorrectionService;
use App\Services\Attendance\AttendanceService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeAttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceCorrectionService $corrections,
        private readonly PlatformAccessService $access,
        private readonly EntitlementService $entitlements,
    ) {}

    public function status(Request $request): JsonResponse
    {
        [$companyId, $employeeId, $employee] = $this->identity($request, 'employee.attendance.view');
        $status = $this->attendance->status($companyId, $employeeId);
        if (in_array(mb_strtolower($employee->status), ['terminated', 'resigned'], true)
            || ! $request->user()->hasCompanyPermission($companyId, 'employee.attendance.clock')) {
            $status['allowed_actions'] = [];
        }

        return response()->json($status);
    }

    public function clockIn(Request $request): JsonResponse
    {
        return $this->transition($request, 'CLOCK_IN');
    }

    public function clockOut(Request $request): JsonResponse
    {
        return $this->transition($request, 'CLOCK_OUT');
    }

    public function breakStart(Request $request): JsonResponse
    {
        return $this->transition($request, 'BREAK_START');
    }

    public function breakEnd(Request $request): JsonResponse
    {
        return $this->transition($request, 'BREAK_END');
    }

    public function index(Request $request): JsonResponse
    {
        [$companyId, $employeeId] = $this->identity($request, 'employee.attendance.view');
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'], 'state' => ['sometimes', 'in:CLOCKED_IN,ON_BREAK,CLOCKED_OUT'],
        ]);
        $this->boundedRange($data['from'], $data['to']);
        $query = AttendanceSession::query()->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->whereBetween('work_date', [$data['from'], $data['to']])->with(['breaks', 'revisions']);
        if (isset($data['state'])) {
            $query->where('state', $data['state']);
        }
        $page = $query->orderByDesc('work_date')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (AttendanceSession $session): array => $this->attendance->present($session))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]]);
    }

    public function show(Request $request, string $session): JsonResponse
    {
        [$companyId, $employeeId] = $this->identity($request, 'employee.attendance.view');
        $model = AttendanceSession::query()->where('company_id', $companyId)->where('employee_id', $employeeId)->with(['breaks', 'revisions'])->findOrFail($session);

        return response()->json($this->attendance->present($model));
    }

    public function calendar(Request $request): JsonResponse
    {
        [$companyId, $employeeId] = $this->identity($request, 'employee.attendance.view');
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month'], 'UTC');
        if ($month === false) {
            throw new PlatformException('ATTENDANCE_MONTH_INVALID', 'Month must use YYYY-MM.', 422);
        }
        $sessions = AttendanceSession::query()->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->whereBetween('work_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->select(['id', 'work_date', 'state'])->orderBy('work_date')->get();

        return response()->json(['month' => $data['month'], 'days' => $sessions->groupBy(fn (AttendanceSession $session): string => $session->work_date->format('Y-m-d'))
            ->map(fn ($rows, string $date): array => ['date' => $date, 'session_count' => $rows->count(), 'states' => $rows->pluck('state')->all()])->values()->all()]);
    }

    public function corrections(Request $request): JsonResponse
    {
        [$companyId, $employeeId] = $this->identity($request, 'employee.attendance.view');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $page = AttendanceCorrectionRequest::query()->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (AttendanceCorrectionRequest $item): array => $this->correctionPayload($item))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function requestCorrection(Request $request, string $session): JsonResponse
    {
        [$companyId, $employeeId] = $this->identity($request, 'employee.attendance.correction.request');
        $correction = $this->corrections->submit($request, $companyId, $employeeId, $session, $this->correctionData($request), (string) $request->header('Idempotency-Key'));

        return response()->json($this->correctionPayload($correction), $correction->wasRecentlyCreated ? 201 : 200);
    }

    /** @return array<string, mixed> */
    public function correctionData(Request $request): array
    {
        return $request->validate([
            'clock_in_at' => ['required', 'date'], 'clock_out_at' => ['required', 'date'],
            'breaks' => ['present', 'array', 'max:50'], 'breaks.*.started_at' => ['required', 'date'], 'breaks.*.ended_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
    }

    /** @return array<string, mixed> */
    public function correctionPayload(AttendanceCorrectionRequest $item): array
    {
        return [
            'id' => $item->id, 'session_id' => $item->attendance_session_id, 'kind' => $item->kind,
            'status' => $item->status, 'original' => $item->original_snapshot, 'proposed' => $item->proposed_snapshot,
            'reason' => $item->reason, 'decision_reason' => $item->decision_reason,
            'submitted_at' => $item->created_at?->toIso8601String(), 'decided_at' => $item->decided_at?->toIso8601String(),
        ];
    }

    /** @return array{string, string, Employee} */
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

        return [$companyId, $employee->id, $employee];
    }

    private function transition(Request $request, string $action): JsonResponse
    {
        [$companyId, $employeeId] = $this->identity($request, 'employee.attendance.clock');
        if ($request->all() !== []) {
            throw new PlatformException('ATTENDANCE_PAYLOAD_INVALID', 'Attendance clock actions do not accept client-supplied timestamps or employee IDs.', 422);
        }

        return response()->json($this->attendance->transition($request, $companyId, $employeeId, $action, (string) $request->header('Idempotency-Key')));
    }

    private function boundedRange(string $from, string $to): void
    {
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366) {
            throw new PlatformException('ATTENDANCE_RANGE_TOO_LARGE', 'Attendance history range must not exceed 366 days.', 422);
        }
    }
}
