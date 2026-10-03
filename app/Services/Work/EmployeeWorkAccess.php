<?php

namespace App\Services\Work;

use App\Exceptions\PlatformException;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeTask;
use App\Models\EmployeeTicket;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\Request;

class EmployeeWorkAccess
{
    public function __construct(private readonly PlatformAccessService $access, private readonly EntitlementService $entitlements) {}

    public function company(Request $request, string $permission): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->entitlements->enforceRequest($companyId, 'api/v1/payroll');
        $this->access->authorize($request->user(), $companyId, $permission);

        return $companyId;
    }

    /** @return array{string, Employee} */
    public function employee(Request $request, string $permission, bool $write = false): array
    {
        $companyId = $this->company($request, $permission);
        $membership = CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)->where('is_active', true)->firstOrFail();
        if ($membership->employee_id === null) {
            throw new PlatformException('EMPLOYEE_IDENTITY_NOT_LINKED', 'No employee identity is linked to this company membership.', 409);
        }
        $employee = Employee::query()->where('company_id', $companyId)->findOrFail($membership->employee_id);
        if ($write && in_array(mb_strtolower($employee->status), ['resigned', 'terminated'], true)) {
            throw new PlatformException('EMPLOYEE_WORK_INACTIVE', 'Former employees may only read historical work.', 403);
        }

        return [$companyId, $employee];
    }

    public function task(string $companyId, string $id, ?string $employeeId = null): EmployeeTask
    {
        $query = EmployeeTask::query()->where('company_id', $companyId);
        if ($employeeId !== null) {
            $query->where('assigned_employee_id', $employeeId);
        }

        return $query->findOrFail($id);
    }

    public function ticket(string $companyId, string $id, ?string $employeeId = null): EmployeeTicket
    {
        $query = EmployeeTicket::query()->where('company_id', $companyId);
        if ($employeeId !== null) {
            $query->where('employee_id', $employeeId);
        }

        return $query->findOrFail($id);
    }
}
