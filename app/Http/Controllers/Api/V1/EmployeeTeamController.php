<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeTeam;
use App\Models\EmployeeTeamMembership;
use App\Services\AuditService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeTeamController extends Controller
{
    public function __construct(
        private readonly PlatformAccessService $access,
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
    ) {}

    public function directory(Request $request): JsonResponse
    {
        [$companyId] = $this->employeeAccess($request, 'employee.directory.view');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50'],
            'search' => ['sometimes', 'string', 'max:100']]);
        $query = Employee::query()->where('company_id', $companyId)->whereNotIn('status', ['resigned', 'terminated']);
        if (isset($data['search']) && trim($data['search']) !== '') {
            $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($data['search'])).'%';
            $query->where(function (Builder $employees) use ($search): void {
                $employees->where('full_name', 'like', $search)->orWhere('department', 'like', $search)
                    ->orWhere('designation', 'like', $search);
            });
        }
        $page = $query->orderBy('full_name')->orderBy('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (Employee $employee): array => $this->employeeCard($employee))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function myTeams(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->employeeAccess($request, 'employee.teams.view');
        $teamIds = EmployeeTeamMembership::query()->where('company_id', $companyId)->where('employee_id', $employee->id)
            ->where('is_active', true)->pluck('employee_team_id');
        $teams = EmployeeTeam::query()->where('company_id', $companyId)->whereIn('id', $teamIds)
            ->orderBy('name')->get()->map(fn (EmployeeTeam $team): array => $this->teamCard($team))->all();
        $manager = $employee->manager_employee_id === null ? null : Employee::query()->where('company_id', $companyId)
            ->whereNotIn('status', ['resigned', 'terminated'])->find($employee->manager_employee_id);

        return response()->json(['data' => $teams,
            'manager' => $manager === null ? null : $this->employeeCard($manager),
            'direct_report_count' => Employee::query()->where('company_id', $companyId)
                ->where('manager_employee_id', $employee->id)->whereNotIn('status', ['resigned', 'terminated'])->count()]);
    }

    public function myTeamDetail(Request $request, string $team): JsonResponse
    {
        [$companyId, $employee] = $this->employeeAccess($request, 'employee.teams.view');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $model = EmployeeTeam::query()->where('company_id', $companyId)->findOrFail($team);
        abort_unless(EmployeeTeamMembership::query()->where('company_id', $companyId)->where('employee_team_id', $model->id)
            ->where('employee_id', $employee->id)->where('is_active', true)->exists(), 404);
        $memberIds = EmployeeTeamMembership::query()->where('company_id', $companyId)->where('employee_team_id', $model->id)
            ->where('is_active', true)->pluck('employee_id');
        $page = Employee::query()->where('company_id', $companyId)->whereIn('id', $memberIds)
            ->whereNotIn('status', ['resigned', 'terminated'])->orderBy('full_name')->orderBy('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['team' => $this->teamCard($model),
            'data' => $page->getCollection()->map(fn (Employee $member): array => $this->employeeCard($member))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function directReports(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->employeeAccess($request, 'employee.teams.view');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $page = Employee::query()->where('company_id', $companyId)->where('manager_employee_id', $employee->id)
            ->whereNotIn('status', ['resigned', 'terminated'])->orderBy('full_name')->orderBy('id')
            ->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (Employee $report): array => $this->employeeCard($report))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'teams.view');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $page = EmployeeTeam::query()->where('company_id', $companyId)->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (EmployeeTeam $team): array => $this->teamCard($team))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function adminShow(Request $request, string $team): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'teams.view');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $model = $this->owned($companyId)->findOrFail($team);
        $memberIds = EmployeeTeamMembership::query()->where('company_id', $companyId)->where('employee_team_id', $team)
            ->where('is_active', true)->pluck('employee_id');
        $page = Employee::query()->where('company_id', $companyId)->whereIn('id', $memberIds)
            ->orderBy('full_name')->orderBy('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['team' => $this->teamCard($model),
            'data' => $page->getCollection()->map(fn (Employee $member): array => $this->employeeCard($member))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'teams.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $keyHash = $this->keyHash($request);
        $payloadHash = hash('sha256', $data['name']);
        $team = DB::transaction(function () use ($request, $companyId, $data, $keyHash, $payloadHash): EmployeeTeam {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $prior = $this->owned($companyId)->where('created_by', $request->user()->id)
                ->where('create_request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                if (! hash_equals((string) $prior->create_payload_hash, $payloadHash)) {
                    throw new PlatformException('TEAM_IDEMPOTENCY_CONFLICT', 'This idempotency key was used for a different team.', 409);
                }

                return $prior;
            }
            if ($this->owned($companyId)->where('name', $data['name'])->exists()) {
                throw new PlatformException('TEAM_NAME_CONFLICT', 'A team with this name already exists.', 409);
            }
            $created = EmployeeTeam::query()->create(['company_id' => $companyId, 'name' => $data['name'], 'created_by' => $request->user()->id]);
            $created->forceFill(['version' => 1, 'create_request_key_hash' => $keyHash, 'create_payload_hash' => $payloadHash])->save();
            $this->audit->record($request, $request->user(), $companyId, 'employee_team_created', 'employee_teams', $created,
                null, ['team_id' => $created->id]);

            return $created;
        });

        return response()->json($this->teamCard($team), $team->wasRecentlyCreated ? 201 : 200);
    }

    public function rename(Request $request, string $team): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'teams.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($request, $companyId, $team, $data): EmployeeTeam {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = $this->owned($companyId)->lockForUpdate()->findOrFail($team);
            if ($current->name === $data['name']) {
                return $current;
            }
            $this->assertVersion($current->version, (int) $data['version']);
            if ($this->owned($companyId)->where('name', $data['name'])->whereKeyNot($team)->exists()) {
                throw new PlatformException('TEAM_NAME_CONFLICT', 'A team with this name already exists.', 409);
            }
            $current->forceFill(['name' => $data['name'], 'version' => $current->version + 1, 'updated_by' => $request->user()->id])->save();
            $this->audit->record($request, $request->user(), $companyId, 'employee_team_renamed', 'employee_teams', $current,
                ['version' => $data['version']], ['version' => $current->version]);

            return $current;
        });

        return response()->json($this->teamCard($model));
    }

    public function addMember(Request $request, string $team): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'teams.manage');
        $data = $request->validate(['employee_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($request, $companyId, $team, $data): EmployeeTeam {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = $this->owned($companyId)->lockForUpdate()->findOrFail($team);
            $employee = Employee::query()->where('company_id', $companyId)->findOrFail($data['employee_id']);
            $this->assertCurrentEmployee($employee);
            $membership = EmployeeTeamMembership::query()->where('company_id', $companyId)
                ->where('employee_team_id', $team)->where('employee_id', $data['employee_id'])->first();
            if ($membership?->is_active === true) {
                return $current;
            }
            $this->assertVersion($current->version, (int) $data['version']);
            if ($membership === null) {
                EmployeeTeamMembership::query()->create(['company_id' => $companyId, 'employee_team_id' => $team,
                    'employee_id' => $data['employee_id'], 'created_by' => $request->user()->id]);
            } else {
                $membership->forceFill(['is_active' => true, 'left_at' => null, 'updated_by' => $request->user()->id])->save();
            }
            $current->forceFill(['version' => $current->version + 1, 'updated_by' => $request->user()->id])->save();
            $this->audit->record($request, $request->user(), $companyId, 'employee_team_member_added', 'employee_teams', $current,
                null, ['employee_id' => $data['employee_id'], 'version' => $current->version]);

            return $current;
        });

        return response()->json($this->teamCard($model));
    }

    public function removeMember(Request $request, string $team, string $employee): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'teams.manage');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($request, $companyId, $team, $employee, $data): EmployeeTeam {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = $this->owned($companyId)->lockForUpdate()->findOrFail($team);
            $membership = EmployeeTeamMembership::query()->where('company_id', $companyId)
                ->where('employee_team_id', $team)->where('employee_id', $employee)->lockForUpdate()->firstOrFail();
            if (! $membership->is_active) {
                return $current;
            }
            $this->assertVersion($current->version, (int) $data['version']);
            if ($current->lead_employee_id === $employee) {
                throw new PlatformException('TEAM_LEAD_MEMBERSHIP_REQUIRED', 'Assign another lead before removing this member.', 409);
            }
            $membership->forceFill(['is_active' => false, 'left_at' => now(), 'updated_by' => $request->user()->id])->save();
            $current->forceFill(['version' => $current->version + 1, 'updated_by' => $request->user()->id])->save();
            $this->audit->record($request, $request->user(), $companyId, 'employee_team_member_removed', 'employee_teams', $current,
                null, ['employee_id' => $employee, 'version' => $current->version]);

            return $current;
        });

        return response()->json($this->teamCard($model));
    }

    public function setLead(Request $request, string $team): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'teams.manage');
        $data = $request->validate(['employee_id' => ['present', 'nullable', 'uuid'], 'version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($request, $companyId, $team, $data): EmployeeTeam {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = $this->owned($companyId)->lockForUpdate()->findOrFail($team);
            if ($current->lead_employee_id === $data['employee_id']) {
                return $current;
            }
            $this->assertVersion($current->version, (int) $data['version']);
            if ($data['employee_id'] !== null) {
                $employee = Employee::query()->where('company_id', $companyId)->findOrFail($data['employee_id']);
                $this->assertCurrentEmployee($employee);
                abort_unless(EmployeeTeamMembership::query()->where('company_id', $companyId)->where('employee_team_id', $team)
                    ->where('employee_id', $data['employee_id'])->where('is_active', true)->exists(), 409);
            }
            $current->forceFill(['lead_employee_id' => $data['employee_id'],
                'version' => $current->version + 1, 'updated_by' => $request->user()->id])->save();
            $this->audit->record($request, $request->user(), $companyId, 'employee_team_lead_changed', 'employee_teams', $current,
                null, ['lead_employee_id' => $data['employee_id'], 'version' => $current->version]);

            return $current;
        });

        return response()->json($this->teamCard($model));
    }

    public function setManager(Request $request, string $employee): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'teams.manage');
        $data = $request->validate(['manager_employee_id' => ['present', 'nullable', 'uuid'],
            'version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($request, $companyId, $employee, $data): Employee {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = Employee::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($employee);
            if ($current->manager_employee_id === $data['manager_employee_id']) {
                return $current;
            }
            $this->assertVersion($current->manager_version, (int) $data['version']);
            if ($data['manager_employee_id'] !== null) {
                $this->assertCurrentEmployee($current);
            }
            $candidate = $data['manager_employee_id'];
            $visited = [];
            while ($candidate !== null) {
                if ($candidate === $current->id || isset($visited[$candidate])) {
                    throw new PlatformException('MANAGER_CYCLE_FORBIDDEN', 'A reporting line cannot contain a cycle.', 409);
                }
                $visited[$candidate] = true;
                $manager = Employee::query()->where('company_id', $companyId)->findOrFail($candidate);
                $this->assertCurrentEmployee($manager);
                $candidate = $manager->manager_employee_id;
            }
            $current->forceFill(['manager_employee_id' => $data['manager_employee_id'],
                'manager_version' => $current->manager_version + 1, 'updated_by' => $request->user()->id])->save();
            $this->audit->record($request, $request->user(), $companyId, 'employee_manager_changed', 'employee_teams', $current,
                null, ['manager_employee_id' => $data['manager_employee_id'], 'version' => $current->manager_version]);

            return $current;
        });

        return response()->json(['employee_id' => $model->id, 'manager_employee_id' => $model->manager_employee_id,
            'version' => $model->manager_version]);
    }

    public function showManager(Request $request, string $employee): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'teams.view');
        $model = Employee::query()->where('company_id', $companyId)->findOrFail($employee);

        return response()->json(['employee_id' => $model->id, 'manager_employee_id' => $model->manager_employee_id,
            'version' => $model->manager_version]);
    }

    /** @return array{string, Employee} */
    private function employeeAccess(Request $request, string $permission): array
    {
        $companyId = $this->adminAccess($request, $permission);
        $membership = CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)
            ->where('is_active', true)->firstOrFail();
        if ($membership->employee_id === null) {
            throw new PlatformException('EMPLOYEE_IDENTITY_NOT_LINKED', 'No employee identity is linked to this company membership.', 409);
        }
        $employee = Employee::query()->where('company_id', $companyId)->findOrFail($membership->employee_id);
        if (in_array($employee->status, ['resigned', 'terminated'], true)) {
            throw new PlatformException('EMPLOYEE_DIRECTORY_INACTIVE', 'Former employees cannot view the current colleague directory.', 403);
        }

        return [$companyId, $employee];
    }

    private function adminAccess(Request $request, string $permission): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->entitlements->enforceRequest($companyId, 'api/v1/payroll');
        $this->access->authorize($request->user(), $companyId, $permission);

        return $companyId;
    }

    private function assertCurrentEmployee(Employee $employee): void
    {
        if (in_array($employee->status, ['resigned', 'terminated'], true)) {
            throw new PlatformException('EMPLOYEE_INACTIVE', 'A former employee cannot be assigned to a current team or reporting line.', 409);
        }
    }

    private function owned(string $companyId): Builder
    {
        return EmployeeTeam::query()->where('company_id', $companyId);
    }

    private function keyHash(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        if (mb_strlen($key) < 8 || mb_strlen($key) > 200) {
            throw new PlatformException('TEAM_KEY_REQUIRED', 'An Idempotency-Key of 8 to 200 characters is required.', 422);
        }

        return hash('sha256', $key);
    }

    private function assertVersion(int $current, int $submitted): void
    {
        if ($current !== $submitted) {
            throw new PlatformException('TEAM_VERSION_STALE', 'The team or reporting line changed; refresh before editing.', 409);
        }
        if ($current >= 4_294_967_295) {
            throw new PlatformException('TEAM_VERSION_EXHAUSTED', 'The team or reporting line can no longer be updated.', 409);
        }
    }

    /** @return array<string, mixed> */
    private function employeeCard(Employee $employee): array
    {
        return ['id' => $employee->id, 'full_name' => $employee->full_name,
            'department' => $employee->department, 'designation' => $employee->designation,
            'location' => $employee->location];
    }

    /** @return array<string, mixed> */
    private function teamCard(EmployeeTeam $team): array
    {
        $lead = $team->lead_employee_id === null ? null : Employee::query()->where('company_id', $team->company_id)
            ->whereNotIn('status', ['resigned', 'terminated'])->find($team->lead_employee_id);

        return ['id' => $team->id, 'name' => $team->name, 'version' => $team->version,
            'lead' => $lead === null ? null : $this->employeeCard($lead),
            'member_count' => EmployeeTeamMembership::query()->join('employees',
                'employees.id', '=', 'employee_team_memberships.employee_id')
                ->where('employee_team_memberships.company_id', $team->company_id)
                ->where('employee_team_memberships.employee_team_id', $team->id)
                ->where('employee_team_memberships.is_active', true)
                ->whereNotIn('employees.status', ['resigned', 'terminated'])->count()];
    }
}
