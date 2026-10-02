<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Services\AuditService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeLinkController extends Controller
{
    public function __construct(
        private readonly PlatformAccessService $access,
        private readonly AuditService $audit,
    ) {}

    public function show(Request $request, CompanyUser $membership): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'employee.links.manage');
        abort_unless($membership->company_id === $companyId, 404);

        return response()->json([
            'membership_id' => $membership->id,
            'employee_id' => $membership->employee_id,
            'linked' => $membership->employee_id !== null,
        ]);
    }

    public function update(Request $request, CompanyUser $membership): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'employee.links.manage');
        abort_unless($membership->company_id === $companyId, 404);
        $data = $request->validate(['employee_id' => ['required', 'uuid']]);

        $linked = DB::transaction(function () use ($request, $membership, $companyId, $data): CompanyUser {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = CompanyUser::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($membership->id);
            abort_unless($current->is_active, 409, 'Only an active membership can be linked to an employee.');
            $employee = Employee::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($data['employee_id']);
            abort_if($current->employee_id !== null && $current->employee_id !== $employee->id, 409, 'Unlink the current employee before linking another.');
            abort_if(CompanyUser::query()->where('employee_id', $employee->id)->whereKeyNot($current->id)->exists(), 409, 'This employee is already linked to another membership.');
            if ($current->employee_id === null) {
                $current->forceFill(['employee_id' => $employee->id])->save();
                $this->audit->record($request, $request->user(), $companyId, 'employee_identity_linked', 'employee_identity', $current, null, ['employee_id' => $employee->id]);
            }

            return $current;
        });

        return response()->json(['membership_id' => $linked->id, 'employee_id' => $linked->employee_id, 'linked' => true]);
    }

    public function destroy(Request $request, CompanyUser $membership): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, 'employee.links.manage');
        abort_unless($membership->company_id === $companyId, 404);

        $unlinked = DB::transaction(function () use ($request, $membership, $companyId): CompanyUser {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = CompanyUser::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($membership->id);
            if ($current->employee_id !== null) {
                $previousId = $current->employee_id;
                $current->forceFill(['employee_id' => null])->save();
                $this->audit->record($request, $request->user(), $companyId, 'employee_identity_unlinked', 'employee_identity', $current, ['employee_id' => $previousId], null);
            }

            return $current;
        });

        return response()->json(['membership_id' => $unlinked->id, 'employee_id' => null, 'linked' => false]);
    }
}
