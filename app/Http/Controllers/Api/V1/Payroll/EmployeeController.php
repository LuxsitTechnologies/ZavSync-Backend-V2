<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payroll\StoreEmployeeRequest;
use App\Http\Requests\Api\V1\Payroll\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

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
        $employee = Employee::query()->create([...$request->validated(), 'company_id' => $this->companyId($request), 'created_by' => $request->user()->id]);
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
        $model = $this->employee($request, $employee);
        $old = $model->toArray();
        $model->update([...$request->validated(), 'updated_by' => $request->user()->id]);
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
