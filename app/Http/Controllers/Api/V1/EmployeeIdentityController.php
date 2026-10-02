<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeSelfProfileResource;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeIdentityController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access) {}

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
            'self_editable' => false,
        ]);
    }
}
