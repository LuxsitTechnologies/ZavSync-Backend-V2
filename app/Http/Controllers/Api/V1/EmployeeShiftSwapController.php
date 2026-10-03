<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployeeShiftSwap;
use App\Services\Scheduling\EmployeeScheduleService;
use App\Services\Scheduling\EmployeeShiftSwapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeShiftSwapController extends Controller
{
    public function __construct(private readonly EmployeeScheduleService $schedule, private readonly EmployeeShiftSwapService $swaps) {}

    public function employeeIndex(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->schedule->employeeAccess($request, 'employee.schedule.view');
        $page = EmployeeShiftSwap::query()->where('company_id', $companyId)
            ->where(function ($query) use ($employee): void {
                $query->where('requester_employee_id', $employee->id)->orWhere('target_employee_id', $employee->id);
            })->orderByDesc('created_at')->paginate(20);

        return response()->json(['data' => $page->getCollection()->map(fn (EmployeeShiftSwap $swap): array => $this->card($swap))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function employeeShow(Request $request, string $swap): JsonResponse
    {
        [$companyId, $employee] = $this->schedule->employeeAccess($request, 'employee.schedule.view');
        $model = EmployeeShiftSwap::query()->where('company_id', $companyId)->where(function ($query) use ($employee): void {
            $query->where('requester_employee_id', $employee->id)->orWhere('target_employee_id', $employee->id);
        })->findOrFail($swap);

        return response()->json($this->card($model));
    }

    public function store(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->schedule->employeeAccess($request, 'employee.schedule.swap.request');
        $data = $request->validate(['from_assignment_id' => ['required', 'uuid'],
            'to_assignment_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:2000']]);
        $keyHash = $this->schedule->keyHash($request);
        $swap = $this->swaps->request($request, $companyId, $employee, $data['from_assignment_id'],
            $data['to_assignment_id'], $data['reason'], $keyHash);

        return response()->json($this->card($swap), $swap->wasRecentlyCreated ? 201 : 200);
    }

    public function accept(Request $request, string $swap): JsonResponse
    {
        return $this->respond($request, $swap, true);
    }

    public function decline(Request $request, string $swap): JsonResponse
    {
        return $this->respond($request, $swap, false);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $companyId = $this->schedule->adminAccess($request, 'schedules.view');
        $page = EmployeeShiftSwap::query()->where('company_id', $companyId)->orderByDesc('created_at')->paginate(20);

        return response()->json(['data' => $page->getCollection()->map(fn (EmployeeShiftSwap $swap): array => $this->card($swap))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function adminShow(Request $request, string $swap): JsonResponse
    {
        $companyId = $this->schedule->adminAccess($request, 'schedules.view');

        return response()->json($this->card(EmployeeShiftSwap::query()->where('company_id', $companyId)->findOrFail($swap)));
    }

    public function approve(Request $request, string $swap): JsonResponse
    {
        return $this->decide($request, $swap, true);
    }

    public function reject(Request $request, string $swap): JsonResponse
    {
        return $this->decide($request, $swap, false);
    }

    private function respond(Request $request, string $swap, bool $accept): JsonResponse
    {
        [$companyId, $employee] = $this->schedule->employeeAccess($request, 'employee.schedule.swap.respond');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return response()->json($this->card($this->swaps->respond($request, $companyId, $employee, $swap, (int) $data['version'], $accept)));
    }

    private function decide(Request $request, string $swap, bool $approve): JsonResponse
    {
        $companyId = $this->schedule->adminAccess($request, 'schedules.swaps.decide');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'],
            'reason' => [$approve ? 'nullable' : 'required', 'string', 'max:2000']]);
        $model = $this->swaps->decide($request, $companyId, $swap, (int) $data['version'], $approve, $data['reason'] ?? null);

        return response()->json($this->card($model));
    }

    private function card(EmployeeShiftSwap $swap): array
    {
        return ['id' => $swap->id, 'requester_employee_id' => $swap->requester_employee_id,
            'target_employee_id' => $swap->target_employee_id, 'from_assignment_id' => $swap->from_assignment_id,
            'to_assignment_id' => $swap->to_assignment_id, 'status' => $swap->status, 'version' => $swap->version,
            'reason' => $swap->reason, 'decision_reason' => $swap->decision_reason,
            'target_accepted_at' => $swap->target_accepted_at?->toIso8601String(),
            'decided_at' => $swap->decided_at?->toIso8601String()];
    }
}
