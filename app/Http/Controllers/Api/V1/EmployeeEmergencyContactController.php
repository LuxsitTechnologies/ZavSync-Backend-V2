<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeEmergencyContact;
use App\Services\AuditService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeEmergencyContactController extends Controller
{
    public function __construct(
        private readonly PlatformAccessService $access,
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->identity($request, 'employee.self.view');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $page = $this->contacts($companyId, $employee->id)->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (EmployeeEmergencyContact $contact): array => $this->present($contact))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, string $contact): JsonResponse
    {
        [$companyId, $employee] = $this->identity($request, 'employee.self.view');

        return response()->json($this->present($this->contacts($companyId, $employee->id)->findOrFail($contact)));
    }

    public function store(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->identity($request, 'employee.profile.edit', true);
        $data = $request->validate($this->contactRules());
        $key = (string) $request->header('Idempotency-Key');
        if (mb_strlen($key) < 8 || mb_strlen($key) > 200) {
            throw new PlatformException('EMERGENCY_CONTACT_KEY_REQUIRED', 'An Idempotency-Key of 8 to 200 characters is required.', 422);
        }
        $keyHash = hash('sha256', $key);
        $payloadHash = hash('sha256', json_encode([$data['name'], $data['relationship'], $data['phone']], JSON_THROW_ON_ERROR));
        $contact = DB::transaction(function () use ($request, $companyId, $employee, $data, $keyHash, $payloadHash): EmployeeEmergencyContact {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            Employee::query()->where('company_id', $companyId)->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $prior = EmployeeEmergencyContact::withTrashed()->where('company_id', $companyId)->where('employee_id', $employee->id)
                ->where('create_request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                if ($prior->trashed() || ! hash_equals((string) $prior->create_payload_hash, $payloadHash)) {
                    throw new PlatformException('EMERGENCY_CONTACT_IDEMPOTENCY_CONFLICT', 'This idempotency key was used for different contact content or a removed contact.', 409);
                }

                return $prior;
            }
            $created = EmployeeEmergencyContact::query()->create([
                'company_id' => $companyId, 'employee_id' => $employee->id,
                'name' => $data['name'], 'relationship' => $data['relationship'], 'phone' => $data['phone'],
                'created_by' => $request->user()->id,
            ]);
            $created->forceFill(['version' => 1, 'create_request_key_hash' => $keyHash, 'create_payload_hash' => $payloadHash])->save();
            $this->audit->record($request, $request->user(), $companyId, 'emergency_contact_created', 'employee_profile', $created,
                null, ['contact_id' => $created->id, 'version' => 1]);

            return $created;
        });

        return response()->json($this->present($contact), $contact->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, string $contact): JsonResponse
    {
        [$companyId, $employee] = $this->identity($request, 'employee.profile.edit', true);
        $this->assertOnlyFields($request, ['name', 'relationship', 'phone', 'version']);
        $data = $request->validate([...$this->contactRules(), 'version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($request, $companyId, $employee, $contact, $data): EmployeeEmergencyContact {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = $this->contacts($companyId, $employee->id)->lockForUpdate()->findOrFail($contact);
            if ($current->name === $data['name'] && $current->relationship === $data['relationship'] && $current->phone === $data['phone']) {
                return $current;
            }
            if ($current->version !== (int) $data['version']) {
                throw new PlatformException('EMERGENCY_CONTACT_VERSION_STALE', 'The contact changed; refresh before editing.', 409);
            }
            if ($current->version >= 4_294_967_295) {
                throw new PlatformException('EMERGENCY_CONTACT_VERSION_EXHAUSTED', 'The contact can no longer be updated.', 409);
            }
            $current->forceFill(['name' => $data['name'], 'relationship' => $data['relationship'], 'phone' => $data['phone'],
                'version' => $current->version + 1, 'updated_by' => $request->user()->id])->save();
            $this->audit->record($request, $request->user(), $companyId, 'emergency_contact_updated', 'employee_profile', $current,
                ['version' => $data['version']], ['version' => $current->version]);

            return $current;
        });

        return response()->json($this->present($model));
    }

    public function destroy(Request $request, string $contact): JsonResponse
    {
        [$companyId, $employee] = $this->identity($request, 'employee.profile.edit', true);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $companyId, $employee, $contact, $data): void {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = EmployeeEmergencyContact::withTrashed()->where('company_id', $companyId)
                ->where('employee_id', $employee->id)->lockForUpdate()->findOrFail($contact);
            if ($current->trashed()) {
                return;
            }
            if ($current->version !== (int) $data['version']) {
                throw new PlatformException('EMERGENCY_CONTACT_VERSION_STALE', 'The contact changed; refresh before removing.', 409);
            }
            $current->delete();
            $this->audit->record($request, $request->user(), $companyId, 'emergency_contact_removed', 'employee_profile', $current,
                ['version' => $current->version], ['removed' => true]);
        });

        return response()->json(['status' => 'REMOVED']);
    }

    /** @return array{string, Employee} */
    private function identity(Request $request, string $permission, bool $write = false): array
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->entitlements->enforceRequest($companyId, 'api/v1/payroll');
        $this->access->authorize($request->user(), $companyId, $permission);
        $membership = CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)
            ->where('is_active', true)->firstOrFail();
        if ($membership->employee_id === null) {
            throw new PlatformException('EMPLOYEE_IDENTITY_NOT_LINKED', 'No employee identity is linked to this company membership.', 409);
        }
        $employee = Employee::query()->where('company_id', $companyId)->findOrFail($membership->employee_id);
        if ($write && in_array($employee->status, ['resigned', 'terminated'], true)) {
            throw new PlatformException('EMPLOYEE_PROFILE_INACTIVE', 'Former employees cannot edit emergency contacts.', 403);
        }

        return [$companyId, $employee];
    }

    /** @return array<string, array<int, string>> */
    private function contactRules(): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'relationship' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:40']];
    }

    private function contacts(string $companyId, string $employeeId): Builder
    {
        return EmployeeEmergencyContact::query()->where('company_id', $companyId)->where('employee_id', $employeeId);
    }

    /** @param list<string> $allowed */
    private function assertOnlyFields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw new PlatformException('EMPLOYEE_PROFILE_FIELD_FORBIDDEN', 'This field is not employee-editable.', 422);
        }
    }

    /** @return array<string, mixed> */
    private function present(EmployeeEmergencyContact $contact): array
    {
        return ['id' => $contact->id, 'name' => $contact->name, 'relationship' => $contact->relationship,
            'phone' => $contact->phone, 'version' => $contact->version,
            'created_at' => $contact->created_at?->toIso8601String()];
    }
}
