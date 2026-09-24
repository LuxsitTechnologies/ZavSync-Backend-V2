<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payroll\UpdateEmployeePayrollProfileRequest;
use App\Http\Resources\EmployeePayrollProfileResource;
use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Services\AuditService;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeePayrollProfileController extends Controller
{
    public function __construct(private readonly PayrollService $payroll, private readonly AuditService $audit) {}

    public function index(Request $request, string $employee): AnonymousResourceCollection
    {
        $this->authorizeView($request);
        $employeeModel = $this->employee($request, $employee);

        return EmployeePayrollProfileResource::collection(EmployeePayrollProfile::query()->where('company_id', $this->companyId($request))->where('employee_id', $employeeModel->id)->with(['components.component', 'paymentFinancialAccount'])->orderByDesc('effective_from')->get());
    }

    public function store(UpdateEmployeePayrollProfileRequest $request, string $employee): EmployeePayrollProfileResource
    {
        $profile = $this->payroll->createProfile($this->companyId($request), $request->user(), $this->employee($request, $employee), $request->validated());
        $this->audit->record($request, $request->user(), $this->companyId($request), 'create_profile_version', 'payroll', $profile, null, $profile->withoutRelations()->toArray());

        return new EmployeePayrollProfileResource($profile);
    }

    public function show(Request $request, string $employee, string $profile): EmployeePayrollProfileResource
    {
        $this->authorizeView($request);
        $this->employee($request, $employee);
        $model = EmployeePayrollProfile::query()->where('company_id', $this->companyId($request))->where('employee_id', $employee)->with(['components.component', 'paymentFinancialAccount'])->findOrFail($profile);

        return new EmployeePayrollProfileResource($model);
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
