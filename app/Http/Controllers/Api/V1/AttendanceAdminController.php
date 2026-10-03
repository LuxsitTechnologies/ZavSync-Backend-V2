<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceSession;
use App\Services\Attendance\AttendanceCorrectionService;
use App\Services\Attendance\AttendanceService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceAdminController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceCorrectionService $corrections,
        private readonly PlatformAccessService $access,
        private readonly EntitlementService $entitlements,
        private readonly EmployeeAttendanceController $self,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'attendance.view');
        $data = $request->validate([
            'employee_id' => ['sometimes', 'uuid'], 'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'], 'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);
        $query = AttendanceSession::query()->where('company_id', $companyId)->with(['breaks', 'revisions']);
        if (isset($data['employee_id'])) {
            $query->where('employee_id', $data['employee_id']);
        }
        if (isset($data['from'])) {
            $query->whereDate('work_date', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->whereDate('work_date', '<=', $data['to']);
        }
        $page = $query->orderByDesc('work_date')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (AttendanceSession $session): array => [
            'employee_id' => $session->employee_id, ...$this->attendance->present($session),
        ])->all(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, string $session): JsonResponse
    {
        $companyId = $this->authorize($request, 'attendance.view');
        $model = AttendanceSession::query()->where('company_id', $companyId)->with(['breaks', 'revisions', 'corrections'])->findOrFail($session);

        return response()->json(['employee_id' => $model->employee_id, ...$this->attendance->present($model),
            'corrections' => $model->corrections->map(fn (AttendanceCorrectionRequest $item): array => $this->self->correctionPayload($item))->all()]);
    }

    public function correctionQueue(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'attendance.corrections.manage');
        $data = $request->validate(['status' => ['sometimes', 'in:PENDING,APPROVED,REJECTED'], 'per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $query = AttendanceCorrectionRequest::query()->where('company_id', $companyId);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (AttendanceCorrectionRequest $item): array => [
            'employee_id' => $item->employee_id, ...$this->self->correctionPayload($item),
        ])->all(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function approve(Request $request, string $correction): JsonResponse
    {
        return $this->decide($request, $correction, true);
    }

    public function reject(Request $request, string $correction): JsonResponse
    {
        return $this->decide($request, $correction, false);
    }

    public function intervene(Request $request, string $session): JsonResponse
    {
        $companyId = $this->authorize($request, 'attendance.manage');
        $data = $this->self->correctionData($request);
        $employeeId = AttendanceSession::query()->where('company_id', $companyId)->findOrFail($session)->employee_id;
        $item = $this->corrections->submit($request, $companyId, $employeeId, $session, $data, (string) $request->header('Idempotency-Key'), true);

        return response()->json($this->self->correctionPayload($item), $item->wasRecentlyCreated ? 201 : 200);
    }

    private function decide(Request $request, string $correction, bool $approve): JsonResponse
    {
        $companyId = $this->authorize($request, 'attendance.corrections.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $item = $this->corrections->decide($request, $companyId, $correction, $approve, $data['reason']);

        return response()->json($this->self->correctionPayload($item));
    }

    private function authorize(Request $request, string $permission): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->entitlements->enforceRequest($companyId, 'api/v1/payroll');
        $this->access->authorize($request->user(), $companyId, $permission);

        return $companyId;
    }
}
