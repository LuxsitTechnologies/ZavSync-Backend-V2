<?php

namespace App\Services\Scheduling;

use App\Exceptions\PlatformException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class EmployeeScheduleService
{
    public function __construct(
        private readonly PlatformAccessService $access,
        private readonly EntitlementService $entitlements,
    ) {}

    public function adminAccess(Request $request, string $permission): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->entitlements->enforceRequest($companyId, 'api/v1/payroll');
        $this->access->authorize($request->user(), $companyId, $permission);

        return $companyId;
    }

    /** @return array{string, Employee} */
    public function employeeAccess(Request $request, string $permission): array
    {
        $companyId = $this->adminAccess($request, $permission);
        $membership = CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)
            ->where('is_active', true)->firstOrFail();
        if ($membership->employee_id === null) {
            throw new PlatformException('EMPLOYEE_IDENTITY_NOT_LINKED', 'No employee identity is linked to this company membership.', 409);
        }

        return [$companyId, Employee::query()->where('company_id', $companyId)->findOrFail($membership->employee_id)];
    }

    public function assertCurrent(Employee $employee): void
    {
        if (in_array($employee->status, ['resigned', 'terminated'], true)) {
            throw new PlatformException('SCHEDULE_EMPLOYEE_INACTIVE', 'Former employees cannot be assigned or request a shift swap.', 409);
        }
    }

    public function assertFutureDate(string $companyId, string $date): void
    {
        $timezone = (string) Company::query()->whereKey($companyId)->value('timezone');
        if ($date < CarbonImmutable::now($timezone)->toDateString()) {
            throw new PlatformException('SCHEDULE_DATE_PAST', 'Schedule changes cannot be made for past dates.', 409);
        }
    }

    public function assertVersion(int $current, int $submitted): void
    {
        if ($current !== $submitted) {
            throw new PlatformException('SCHEDULE_VERSION_STALE', 'The schedule changed; refresh before editing.', 409);
        }
        if ($current >= 4_294_967_295) {
            throw new PlatformException('SCHEDULE_VERSION_EXHAUSTED', 'The schedule version cannot advance.', 409);
        }
    }

    public function keyHash(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        if (mb_strlen($key) < 8 || mb_strlen($key) > 200) {
            throw new PlatformException('SCHEDULE_KEY_REQUIRED', 'An Idempotency-Key of 8 to 200 characters is required.', 422);
        }

        return hash('sha256', $key);
    }

    public function assertNoConflict(string $companyId, string $employeeId, string $date, string $start, string $end, array $excludedAssignmentIds = []): void
    {
        $query = EmployeeShiftAssignment::query()->join('employee_rota_slots as slots',
            'slots.id', '=', 'employee_shift_assignments.employee_rota_slot_id')
            ->where('employee_shift_assignments.company_id', $companyId)
            ->where('employee_shift_assignments.employee_id', $employeeId)
            ->whereDate('slots.shift_date', $date)
            ->where('slots.start_time', '<', $end)
            ->where('slots.end_time', '>', $start);
        if ($excludedAssignmentIds !== []) {
            $query->whereNotIn('employee_shift_assignments.id', $excludedAssignmentIds);
        }
        if ($query->exists()) {
            throw new PlatformException('SCHEDULE_ASSIGNMENT_CONFLICT', 'This employee already has an overlapping assignment.', 409);
        }
    }

    public function assertFingerprint(?string $stored, string $expected): void
    {
        if ($stored === null || ! hash_equals($stored, $expected)) {
            throw new PlatformException('SCHEDULE_IDEMPOTENCY_CONFLICT', 'This idempotency key was used for a different operation.', 409);
        }
    }
}
