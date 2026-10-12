<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeBeneficiaryAccount;
use App\Services\AuditService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeBeneficiaryAccountController extends Controller
{
    public function __construct(
        private readonly PlatformAccessService $access,
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
    ) {}

    public function show(Request $request, string $employee): JsonResponse
    {
        $companyId = $this->authorizePayrollAdministrator($request);
        $this->employee($companyId, $employee);

        return response()->json($this->present($this->account($companyId, $employee)->firstOrFail()));
    }

    public function store(Request $request, string $employee): JsonResponse
    {
        $companyId = $this->authorizePayrollAdministrator($request);
        $data = $request->validate($this->accountRules());
        $account = DB::transaction(function () use ($request, $companyId, $employee, $data): EmployeeBeneficiaryAccount {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->employee($companyId, $employee, true);
            if ($this->account($companyId, $employee)->exists()) {
                throw new PlatformException('BENEFICIARY_ACCOUNT_EXISTS', 'An employee beneficiary account already exists.', 409);
            }
            $created = EmployeeBeneficiaryAccount::query()->create([
                'company_id' => $companyId, 'employee_id' => $employee, ...$data, 'created_by' => $request->user()->id,
            ]);
            $created->refresh();
            $this->audit->record($request, $request->user(), $companyId, 'employee_beneficiary_created', 'payroll', $created,
                null, ['employee_id' => $employee, 'version' => $created->version]);

            return $created;
        });

        return response()->json($this->present($account), 201);
    }

    public function update(Request $request, string $employee, string $account): JsonResponse
    {
        $companyId = $this->authorizePayrollAdministrator($request);
        $data = $request->validate([...$this->accountRules(), 'version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($request, $companyId, $employee, $account, $data): EmployeeBeneficiaryAccount {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->employee($companyId, $employee, true);
            $current = $this->account($companyId, $employee)->lockForUpdate()->findOrFail($account);
            if ($current->version !== (int) $data['version']) {
                throw new PlatformException('BENEFICIARY_ACCOUNT_VERSION_STALE', 'The beneficiary account changed; refresh before editing.', 409);
            }
            if ($current->version >= 4_294_967_295) {
                throw new PlatformException('BENEFICIARY_ACCOUNT_VERSION_EXHAUSTED', 'The beneficiary account can no longer be updated.', 409);
            }
            $current->forceFill([...collect($data)->except('version')->all(), 'version' => $current->version + 1,
                'updated_by' => $request->user()->id])->save();
            $this->audit->record($request, $request->user(), $companyId, 'employee_beneficiary_updated', 'payroll', $current,
                ['version' => $data['version']], ['version' => $current->version]);

            return $current;
        });

        return response()->json($this->present($model));
    }

    public function destroy(Request $request, string $employee, string $account): JsonResponse
    {
        $companyId = $this->authorizePayrollAdministrator($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $companyId, $employee, $account, $data): void {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $this->employee($companyId, $employee, true);
            $current = $this->account($companyId, $employee)->lockForUpdate()->findOrFail($account);
            if ($current->version !== (int) $data['version']) {
                throw new PlatformException('BENEFICIARY_ACCOUNT_VERSION_STALE', 'The beneficiary account changed; refresh before removing.', 409);
            }
            $current->delete();
            $this->audit->record($request, $request->user(), $companyId, 'employee_beneficiary_removed', 'payroll', $current,
                ['employee_id' => $employee, 'version' => $current->version], ['removed' => true]);
        });

        return response()->json(['status' => 'REMOVED']);
    }

    private function authorizePayrollAdministrator(Request $request): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->entitlements->enforceRequest($companyId, 'api/v1/payroll');
        $this->access->authorize($request->user(), $companyId, 'payroll.configure');

        return $companyId;
    }

    private function employee(string $companyId, string $employeeId, bool $lock = false): Employee
    {
        $query = Employee::query()->where('company_id', $companyId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($employeeId);
    }

    private function account(string $companyId, string $employeeId): Builder
    {
        return EmployeeBeneficiaryAccount::query()->where('company_id', $companyId)->where('employee_id', $employeeId);
    }

    /** @return array<string, array<int, string>> */
    private function accountRules(): array
    {
        return ['account_holder_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'min:4', 'max:50',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (strlen((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $value)) < 4) {
                        $fail('The account number must contain at least four letters or digits.');
                    }
                }],
            'bank_name' => ['required', 'string', 'max:255'], 'branch_name' => ['required', 'string', 'max:255'],
            'swift_code' => ['nullable', 'string', 'max:11']];
    }

    /** @return array<string, mixed> */
    private function present(EmployeeBeneficiaryAccount $account): array
    {
        $significant = preg_replace('/[^A-Za-z0-9]/', '', $account->account_number);

        return ['id' => $account->id, 'employee_id' => $account->employee_id,
            'account_holder_name' => $account->account_holder_name, 'bank_name' => $account->bank_name,
            'branch_name' => $account->branch_name, 'masked_account_number' => '••••'.substr($significant, -4),
            'swift_configured' => filled($account->swift_code), 'version' => $account->version];
    }
}
