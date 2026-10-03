<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeExpenseCategory;
use App\Services\AuditService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeExpenseCategoryController extends Controller
{
    public function __construct(
        private readonly PlatformAccessService $access,
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
    ) {}

    public function employeeIndex(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'employee.expenses.view');
        $membership = CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)
            ->where('is_active', true)->firstOrFail();
        if ($membership->employee_id === null) {
            throw new PlatformException('EMPLOYEE_IDENTITY_NOT_LINKED', 'No employee identity is linked to this company membership.', 409);
        }
        Employee::query()->where('company_id', $companyId)->findOrFail($membership->employee_id);

        return response()->json(['data' => EmployeeExpenseCategory::query()->where('company_id', $companyId)
            ->where('is_active', true)->orderBy('name')->get()->map(fn (EmployeeExpenseCategory $category): array => $this->present($category))->all()]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'expenses.view');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $page = EmployeeExpenseCategory::query()->where('company_id', $companyId)->orderBy('name')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (EmployeeExpenseCategory $category): array => $this->present($category))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'expenses.categories.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $key = (string) $request->header('Idempotency-Key');
        if (mb_strlen($key) < 8 || mb_strlen($key) > 200) {
            throw new PlatformException('EXPENSE_CATEGORY_KEY_REQUIRED', 'An Idempotency-Key of 8 to 200 characters is required.', 422);
        }
        $keyHash = hash('sha256', $key);
        $payloadHash = hash('sha256', $data['name']);
        $category = DB::transaction(function () use ($request, $companyId, $data, $keyHash, $payloadHash): EmployeeExpenseCategory {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $prior = EmployeeExpenseCategory::query()->where('company_id', $companyId)->where('created_by', $request->user()->id)
                ->where('create_request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                if (! hash_equals((string) $prior->create_payload_hash, $payloadHash)) {
                    throw new PlatformException('EXPENSE_CATEGORY_IDEMPOTENCY_CONFLICT', 'This idempotency key was used for a different category.', 409);
                }

                return $prior;
            }
            if (EmployeeExpenseCategory::query()->where('company_id', $companyId)->where('name', $data['name'])->exists()) {
                throw new PlatformException('EXPENSE_CATEGORY_NAME_CONFLICT', 'A category with this name already exists.', 409);
            }
            $created = EmployeeExpenseCategory::query()->create(['company_id' => $companyId,
                'name' => $data['name'], 'created_by' => $request->user()->id]);
            $created->forceFill(['is_active' => true, 'version' => 1, 'create_request_key_hash' => $keyHash,
                'create_payload_hash' => $payloadHash])->save();
            $this->audit->record($request, $request->user(), $companyId, 'expense_category_created', 'employee_expenses', $created,
                null, ['category_id' => $created->id]);

            return $created;
        });

        return response()->json($this->present($category), $category->wasRecentlyCreated ? 201 : 200);
    }

    public function setActive(Request $request, string $category): JsonResponse
    {
        $companyId = $this->authorize($request, 'expenses.categories.manage');
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($request, $companyId, $category, $data): EmployeeExpenseCategory {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = EmployeeExpenseCategory::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($category);
            if ($current->is_active === (bool) $data['is_active']) {
                return $current;
            }
            if ($current->version !== (int) $data['version']) {
                throw new PlatformException('EXPENSE_CATEGORY_VERSION_STALE', 'The category changed; refresh before editing.', 409);
            }
            if ($current->version >= 4_294_967_295) {
                throw new PlatformException('EXPENSE_CATEGORY_VERSION_EXHAUSTED', 'The category can no longer be updated.', 409);
            }
            $current->forceFill(['is_active' => (bool) $data['is_active'], 'version' => $current->version + 1])->save();
            $this->audit->record($request, $request->user(), $companyId, 'expense_category_availability_changed', 'employee_expenses', $current,
                null, ['is_active' => $current->is_active, 'version' => $current->version]);

            return $current;
        });

        return response()->json($this->present($model));
    }

    private function authorize(Request $request, string $permission): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->entitlements->enforceRequest($companyId, 'api/v1/payroll');
        $this->access->authorize($request->user(), $companyId, $permission);

        return $companyId;
    }

    /** @return array<string, mixed> */
    private function present(EmployeeExpenseCategory $category): array
    {
        return ['id' => $category->id, 'name' => $category->name,
            'is_active' => $category->is_active, 'version' => $category->version];
    }
}
