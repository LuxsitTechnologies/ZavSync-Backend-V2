<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CompanyHoliday;
use App\Models\CompanyUser;
use App\Services\AuditService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly EntitlementService $entitlements, private readonly AuditService $audit) {}

    public function employeeIndex(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'employee.leave.view');
        $this->assertEmployeeLink($request, $companyId);

        return $this->listing($request, $companyId, true);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->listing($request, $this->authorize($request, 'holiday.view'), false);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'holiday.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'date' => ['required', 'date_format:Y-m-d'], 'description' => ['nullable', 'string', 'max:2000']]);
        $holiday = CompanyHoliday::query()->create(['company_id' => $companyId, ...$data, 'created_by' => $request->user()->id]);
        $this->audit->record($request, $request->user(), $companyId, 'holiday_created', 'holiday', $holiday, null, $data);

        return response()->json($this->present($holiday), 201);
    }

    public function update(Request $request, string $holiday): JsonResponse
    {
        $companyId = $this->authorize($request, 'holiday.manage');
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'date' => ['sometimes', 'date_format:Y-m-d'], 'description' => ['nullable', 'string', 'max:2000'], 'is_active' => ['sometimes', 'boolean']]);
        $model = CompanyHoliday::query()->where('company_id', $companyId)->findOrFail($holiday);
        $before = $model->only(['name', 'date', 'description', 'is_active']);
        $model->fill($data)->save();
        $this->audit->record($request, $request->user(), $companyId, 'holiday_updated', 'holiday', $model, $before, $data);

        return response()->json($this->present($model));
    }

    private function listing(Request $request, string $companyId, bool $activeOnly): JsonResponse
    {
        $data = $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $query = CompanyHoliday::query()->where('company_id', $companyId);
        if ($activeOnly) {
            $query->where('is_active', true);
        }
        if (isset($data['from'])) {
            $query->whereDate('date', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->whereDate('date', '<=', $data['to']);
        }

        return response()->json(['data' => $query->orderBy('date')->get()->map(fn (CompanyHoliday $item): array => $this->present($item))->all()]);
    }

    /** @return array<string, mixed> */
    private function present(CompanyHoliday $holiday): array
    {
        return ['id' => $holiday->id, 'name' => $holiday->name, 'date' => $holiday->date->toDateString(),
            'description' => $holiday->description, 'is_active' => $holiday->is_active];
    }

    private function authorize(Request $request, string $permission): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->entitlements->enforceRequest($companyId, 'api/v1/payroll');
        $this->access->authorize($request->user(), $companyId, $permission);

        return $companyId;
    }

    private function assertEmployeeLink(Request $request, string $companyId): void
    {
        CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)
            ->where('is_active', true)->whereNotNull('employee_id')->firstOrFail();
    }
}
