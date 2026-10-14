<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payroll\StoreEmployeeRequest;
use App\Http\Requests\Api\V1\Payroll\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Company;
use App\Models\Employee;
use App\Services\AuditService;
use App\Services\Platform\EntitlementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class EmployeeController extends Controller
{
    private const BILLABLE_STATUSES = ['active', 'probation', 'on_leave', 'notice_period'];

    public function __construct(private readonly AuditService $audit, private readonly EntitlementService $entitlements) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);
        $query = Employee::query()->where('company_id', $this->companyId($request))->with(['currentPayrollProfile.components.component']);
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(fn ($employeeQuery) => $employeeQuery->where('full_name', 'like', $search)->orWhere('employee_code', 'like', $search)->orWhere('email', 'like', $search));
        }

        return EmployeeResource::collection($query->orderBy('employee_code')->get());
    }

    public function store(StoreEmployeeRequest $request): EmployeeResource
    {
        $companyId = $this->companyId($request);
        $data = $request->validated();
        $employee = DB::transaction(function () use ($request, $companyId, $data): Employee {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            if (in_array($data['status'], self::BILLABLE_STATUSES, true)) {
                $active = Employee::query()->where('company_id', $companyId)
                    ->whereIn('status', self::BILLABLE_STATUSES)->count();
                $this->entitlements->assertWithinLimit($companyId, 'employees', $active);
            }

            return Employee::query()->create([...$data, 'company_id' => $companyId,
                'created_by' => $request->user()->id]);
        });
        $this->audit->record($request, $request->user(), $this->companyId($request), 'create', 'payroll_employee', $employee, null, $employee->toArray());

        return new EmployeeResource($employee);
    }

    public function show(Request $request, string $employee): EmployeeResource
    {
        $this->authorizeView($request);

        return new EmployeeResource($this->employee($request, $employee)->load(['currentPayrollProfile.components.component']));
    }

    public function update(UpdateEmployeeRequest $request, string $employee): EmployeeResource
    {
        $companyId = $this->companyId($request);
        $data = $request->validated();
        [$model, $old] = DB::transaction(function () use ($request, $companyId, $employee, $data): array {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $model = Employee::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($employee);
            $old = $model->toArray();
            if (isset($data['status']) && in_array($data['status'], self::BILLABLE_STATUSES, true)
                && ! in_array($model->status, self::BILLABLE_STATUSES, true)) {
                $active = Employee::query()->where('company_id', $companyId)
                    ->whereIn('status', self::BILLABLE_STATUSES)->count();
                $this->entitlements->assertWithinLimit($companyId, 'employees', $active);
            }
            $model->update([...$data, 'updated_by' => $request->user()->id]);

            return [$model, $old];
        });
        $this->audit->record($request, $request->user(), $this->companyId($request), 'update', 'payroll_employee', $model, $old, $model->toArray());

        return new EmployeeResource($model->load(['currentPayrollProfile.components.component']));
    }

    private function employee(Request $request, string $id): Employee
    {
        return Employee::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'payroll.view'), 403);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
