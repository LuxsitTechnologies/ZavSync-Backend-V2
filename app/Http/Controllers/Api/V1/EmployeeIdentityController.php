<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeSelfProfileResource;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Services\AuditService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeIdentityController extends Controller
{
    public function __construct(
        private readonly PlatformAccessService $access,
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'employee.self.view');
        $membership = CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->firstOrFail();
        $employee = $membership->employee_id === null ? null : Employee::query()
            ->where('company_id', $companyId)->findOrFail($membership->employee_id);

        return response()->json([
            'linked' => $employee !== null,
            'employee' => $employee === null ? null : new EmployeeSelfProfileResource($employee),
            'self_editable' => $employee !== null && ! in_array($employee->status, ['resigned', 'terminated'], true)
                && $request->user()->hasCompanyPermission($companyId, 'employee.profile.edit')
                && in_array('payroll', $this->entitlements->enabledModules($companyId), true),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->entitlements->enforceRequest($companyId, 'api/v1/payroll');
        $this->access->authorize($request->user(), $companyId, 'employee.profile.edit');
        if (array_diff(array_keys($request->all()), ['address', 'version']) !== []) {
            throw new PlatformException('EMPLOYEE_PROFILE_FIELD_FORBIDDEN', 'Only address is employee-editable.', 422);
        }
        $data = $request->validate(['address' => ['present', 'nullable', 'string', 'max:500'], 'version' => ['required', 'integer', 'min:1']]);
        $employee = DB::transaction(function () use ($request, $companyId, $data): Employee {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $membership = CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)
                ->where('is_active', true)->firstOrFail();
            if ($membership->employee_id === null) {
                throw new PlatformException('EMPLOYEE_IDENTITY_NOT_LINKED', 'No employee identity is linked to this company membership.', 409);
            }
            $model = Employee::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($membership->employee_id);
            if (in_array($model->status, ['resigned', 'terminated'], true)) {
                throw new PlatformException('EMPLOYEE_PROFILE_INACTIVE', 'Former employees cannot edit employment profile details.', 403);
            }
            if ($model->address === $data['address']) {
                return $model;
            }
            if ($model->self_profile_version !== (int) $data['version']) {
                throw new PlatformException('EMPLOYEE_PROFILE_VERSION_STALE', 'The employee profile changed; refresh before editing.', 409);
            }
            if ($model->self_profile_version >= 4_294_967_295) {
                throw new PlatformException('EMPLOYEE_PROFILE_VERSION_EXHAUSTED', 'The employee profile can no longer be updated.', 409);
            }
            $model->forceFill(['address' => $data['address'], 'self_profile_version' => $model->self_profile_version + 1,
                'updated_by' => $request->user()->id])->save();
            $this->audit->record($request, $request->user(), $companyId, 'employee_self_address_updated', 'employee_profile', $model,
                ['version' => $data['version']], ['version' => $model->self_profile_version]);

            return $model;
        });

        return response()->json(['linked' => true, 'employee' => new EmployeeSelfProfileResource($employee), 'self_editable' => true]);
    }
}
