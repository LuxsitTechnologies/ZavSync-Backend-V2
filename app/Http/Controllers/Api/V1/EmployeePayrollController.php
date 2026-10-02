<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeePayslipResource;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeePayrollController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly EntitlementService $entitlements) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        [$companyId, $employeeId] = $this->identity($request);
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);

        return EmployeePayslipResource::collection(
            PayrollEntry::query()
                ->where('company_id', $companyId)->where('employee_id', $employeeId)
                ->whereNotNull('released_at')
                ->with(['batch.period', 'batch.company'])
                ->withSum('paymentAllocations as paid_amount', 'amount')
                ->orderByDesc('released_at')->orderByDesc('id')
                ->paginate($data['per_page'] ?? 12),
        );
    }

    public function show(Request $request, string $entry): EmployeePayslipResource
    {
        [$companyId, $employeeId] = $this->identity($request);
        $payslip = PayrollEntry::query()
            ->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->whereNotNull('released_at')
            ->with(['batch.period', 'batch.company', 'lines'])
            ->withSum('paymentAllocations as paid_amount', 'amount')
            ->findOrFail($entry);

        return new EmployeePayslipResource($payslip);
    }

    /** @return array{string, string} */
    private function identity(Request $request): array
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->entitlements->enforceRequest($companyId, 'api/v1/payroll');
        $this->access->authorize($request->user(), $companyId, 'employee.payroll.view');
        $membership = CompanyUser::query()->where('company_id', $companyId)
            ->where('user_id', $request->user()->id)->where('is_active', true)->firstOrFail();
        if ($membership->employee_id === null) {
            throw new PlatformException('EMPLOYEE_IDENTITY_NOT_LINKED', 'No employee identity is linked to this company membership.', 409);
        }
        Employee::query()->where('company_id', $companyId)->findOrFail($membership->employee_id);

        return [$companyId, $membership->employee_id];
    }
}
