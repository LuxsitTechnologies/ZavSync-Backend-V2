<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeExpenseCategory;
use App\Models\EmployeeExpenseClaim;
use App\Models\EmployeeExpenseClaimEvent;
use App\Services\AuditService;
use App\Services\Platform\DocumentService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\NotificationService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeExpenseClaimController extends Controller
{
    public function __construct(
        private readonly PlatformAccessService $access,
        private readonly EntitlementService $entitlements,
        private readonly DocumentService $documents,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
    ) {}

    public function employeeIndex(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->employeeAccess($request, 'employee.expenses.view');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $page = $this->owned($companyId)->where('employee_id', $employee->id)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (EmployeeExpenseClaim $claim): array => $this->present($claim))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function employeeShow(Request $request, string $claim): JsonResponse
    {
        [$companyId, $employee] = $this->employeeAccess($request, 'employee.expenses.view');

        return response()->json($this->present($this->owned($companyId)->where('employee_id', $employee->id)->findOrFail($claim), true));
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'expenses.view');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,50'],
            'status' => ['sometimes', Rule::in(['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED'])], 'employee_id' => ['sometimes', 'uuid']]);
        $query = $this->owned($companyId);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (isset($data['employee_id'])) {
            Employee::query()->where('company_id', $companyId)->findOrFail($data['employee_id']);
            $query->where('employee_id', $data['employee_id']);
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json(['data' => $page->getCollection()->map(fn (EmployeeExpenseClaim $claim): array => $this->present($claim))->all(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function adminShow(Request $request, string $claim): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'expenses.view');

        return response()->json($this->present($this->owned($companyId)->findOrFail($claim), true));
    }

    public function store(Request $request): JsonResponse
    {
        [$companyId, $employee] = $this->employeeAccess($request, 'employee.expenses.create', true);
        $this->assertOnlyFields($request, ['category_id', 'title', 'description', 'amount_minor', 'expense_date']);
        $this->assertIntegerMoneyInput($request);
        $data = $request->validate($this->claimRules());
        $keyHash = $this->keyHash($request);
        $payloadHash = $this->payloadHash($data);
        $model = DB::transaction(function () use ($request, $companyId, $employee, $data, $keyHash, $payloadHash): EmployeeExpenseClaim {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $prior = $this->owned($companyId)->where('employee_id', $employee->id)->where('create_request_key_hash', $keyHash)->first();
            if ($prior !== null) {
                if (! hash_equals((string) $prior->create_payload_hash, $payloadHash)) {
                    throw new PlatformException('EXPENSE_CLAIM_IDEMPOTENCY_CONFLICT', 'This idempotency key was used for a different claim.', 409);
                }

                return $prior;
            }
            $this->activeCategory($companyId, $data['category_id']);
            $created = EmployeeExpenseClaim::query()->create(['company_id' => $companyId, 'employee_id' => $employee->id,
                'category_id' => $data['category_id'], 'title' => $data['title'], 'description' => $data['description'] ?? null,
                'amount_minor' => (int) $data['amount_minor'], 'currency' => 'PKR', 'expense_date' => $data['expense_date'],
                'created_by' => $request->user()->id]);
            $created->forceFill(['status' => 'DRAFT', 'version' => 1,
                'create_request_key_hash' => $keyHash, 'create_payload_hash' => $payloadHash])->save();
            $this->event($created, 'CREATED', $request->user()->id);
            $this->audit->record($request, $request->user(), $companyId, 'expense_claim_created', 'employee_expenses', $created,
                null, ['claim_id' => $created->id, 'amount_minor' => $created->amount_minor, 'currency' => 'PKR']);

            return $created;
        });

        return response()->json($this->present($model, true), $model->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, string $claim): JsonResponse
    {
        [$companyId, $employee] = $this->employeeAccess($request, 'employee.expenses.edit', true);
        $this->assertOnlyFields($request, ['category_id', 'title', 'description', 'amount_minor', 'expense_date', 'version']);
        $this->assertIntegerMoneyInput($request);
        $data = $request->validate([...$this->claimRules(), 'version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($request, $companyId, $employee, $claim, $data): EmployeeExpenseClaim {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = $this->owned($companyId)->where('employee_id', $employee->id)->lockForUpdate()->findOrFail($claim);
            if ($current->status !== 'DRAFT') {
                throw new PlatformException('EXPENSE_CLAIM_NOT_EDITABLE', 'Only draft claims may be edited.', 409);
            }
            if ($this->sameContent($current, $data)) {
                return $current;
            }
            $this->assertVersion($current->version, (int) $data['version']);
            $this->activeCategory($companyId, $data['category_id']);
            $oldAmount = $current->amount_minor;
            $current->forceFill(['category_id' => $data['category_id'], 'title' => $data['title'],
                'description' => $data['description'] ?? null, 'amount_minor' => (int) $data['amount_minor'],
                'expense_date' => $data['expense_date'], 'version' => $current->version + 1,
                'updated_by' => $request->user()->id])->save();
            $this->event($current, 'EDITED', $request->user()->id);
            $this->audit->record($request, $request->user(), $companyId, 'expense_claim_edited', 'employee_expenses', $current,
                ['amount_minor' => $oldAmount], ['amount_minor' => $current->amount_minor, 'version' => $current->version]);

            return $current;
        });

        return response()->json($this->present($model, true));
    }

    public function uploadReceipt(Request $request, string $claim): JsonResponse
    {
        [$companyId, $employee] = $this->employeeAccess($request, 'employee.expenses.edit', true);
        $data = $request->validate(['file' => ['required', File::types(config('platform.document_mimes'))->max(config('platform.document_max_kilobytes')), 'extensions:'.implode(',', config('platform.document_mimes'))],
            'version' => ['required', 'integer', 'min:1']]);
        $file = $request->file('file');
        $keyHash = $this->keyHash($request);
        $payloadHash = hash('sha256', json_encode([$file->getClientOriginalName(), $file->getSize(), hash_file('sha256', $file->getRealPath())], JSON_THROW_ON_ERROR));
        $newDocument = null;
        try {
            $model = DB::transaction(function () use ($request, $companyId, $employee, $claim, $file, $keyHash, $payloadHash, $data, &$newDocument): EmployeeExpenseClaim {
                Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
                $current = $this->owned($companyId)->where('employee_id', $employee->id)->lockForUpdate()->findOrFail($claim);
                if ($current->receipt_document_id !== null) {
                    if ($current->receipt_request_key_hash !== $keyHash || ! hash_equals((string) $current->receipt_payload_hash, $payloadHash)) {
                        throw new PlatformException('EXPENSE_RECEIPT_CONFLICT', 'This claim already has a different receipt.', 409);
                    }

                    return $current;
                }
                if ($current->status !== 'DRAFT') {
                    throw new PlatformException('EXPENSE_CLAIM_NOT_EDITABLE', 'Only draft claims may receive a receipt.', 409);
                }
                $this->assertVersion($current->version, (int) $data['version']);
                $document = $this->documents->store($companyId, $request->user(), 'expense_claim', $current->id, 'expense_receipt', $file);
                $newDocument = $document;
                $current->forceFill(['receipt_document_id' => $document->id, 'receipt_request_key_hash' => $keyHash,
                    'receipt_payload_hash' => $payloadHash, 'version' => $current->version + 1])->save();
                $this->event($current, 'RECEIPT_ATTACHED', $request->user()->id);
                $this->audit->record($request, $request->user(), $companyId, 'expense_receipt_attached', 'employee_expenses', $current,
                    null, ['claim_id' => $current->id, 'document_id' => $document->id]);

                return $current;
            });
        } catch (\Throwable $exception) {
            if ($newDocument !== null) {
                Storage::disk($newDocument->storage_disk)->delete($newDocument->storage_key);
            }
            throw $exception;
        }

        return response()->json($this->present($model, true));
    }

    public function employeeReceipt(Request $request, string $claim): StreamedResponse
    {
        [$companyId, $employee] = $this->employeeAccess($request, 'employee.expenses.view');

        return $this->downloadReceipt($this->owned($companyId)->where('employee_id', $employee->id)->findOrFail($claim));
    }

    public function adminReceipt(Request $request, string $claim): StreamedResponse
    {
        $companyId = $this->adminAccess($request, 'expenses.view');

        return $this->downloadReceipt($this->owned($companyId)->findOrFail($claim));
    }

    public function submit(Request $request, string $claim): JsonResponse
    {
        [$companyId, $employee] = $this->employeeAccess($request, 'employee.expenses.submit', true);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($request, $companyId, $employee, $claim, $data): EmployeeExpenseClaim {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = $this->owned($companyId)->where('employee_id', $employee->id)->lockForUpdate()->findOrFail($claim);
            if ($current->status === 'SUBMITTED') {
                return $current;
            }
            if ($current->status !== 'DRAFT') {
                throw new PlatformException('EXPENSE_CLAIM_NOT_SUBMITTABLE', 'Only draft claims may be submitted.', 409);
            }
            $this->assertVersion($current->version, (int) $data['version']);
            $this->activeCategory($companyId, $current->category_id);
            $current->forceFill(['status' => 'SUBMITTED', 'submitted_at' => now(),
                'version' => $current->version + 1])->save();
            $this->event($current, 'SUBMITTED', $request->user()->id);
            $this->audit->record($request, $request->user(), $companyId, 'expense_claim_submitted', 'employee_expenses', $current,
                ['status' => 'DRAFT'], ['status' => 'SUBMITTED', 'version' => $current->version]);
            CompanyUser::query()->with('user')->where('company_id', $companyId)->where('is_active', true)
                ->orderBy('id')->chunk(100, function ($memberships) use ($request, $companyId, $current): void {
                    foreach ($memberships as $membership) {
                        if ($membership->user_id === $request->user()->id
                            || ! $membership->user?->hasCompanyPermission($companyId, 'expenses.approve')) {
                            continue;
                        }
                        $this->notifications->createInApp($companyId, $membership->user_id, 'employee.expense.submitted',
                            'Expense claim submitted', 'An expense claim is ready for review.', ['claim_id' => $current->id], '/hrm/expenses');
                    }
                });

            return $current;
        });

        return response()->json($this->present($model, true));
    }

    public function approve(Request $request, string $claim): JsonResponse
    {
        return $this->decide($request, $claim, 'APPROVED');
    }

    public function reject(Request $request, string $claim): JsonResponse
    {
        return $this->decide($request, $claim, 'REJECTED');
    }

    private function decide(Request $request, string $claim, string $status): JsonResponse
    {
        $companyId = $this->adminAccess($request, 'expenses.approve');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'],
            'reason' => [$status === 'REJECTED' ? 'required' : 'nullable', 'string', 'max:2000']]);
        $model = DB::transaction(function () use ($request, $companyId, $claim, $status, $data): EmployeeExpenseClaim {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = $this->owned($companyId)->lockForUpdate()->findOrFail($claim);
            $membership = CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)
                ->where('is_active', true)->firstOrFail();
            if ($membership->employee_id === $current->employee_id) {
                throw new PlatformException('EXPENSE_CLAIM_SELF_APPROVAL_FORBIDDEN', 'An employee cannot decide their own claim.', 403);
            }
            if ($current->status === $status) {
                return $current;
            }
            if ($current->status !== 'SUBMITTED') {
                throw new PlatformException('EXPENSE_CLAIM_NOT_DECIDABLE', 'Only submitted claims may be decided.', 409);
            }
            $this->assertVersion($current->version, (int) $data['version']);
            $current->forceFill(['status' => $status, 'version' => $current->version + 1,
                'decided_at' => now(), 'decided_by' => $request->user()->id,
                'decision_reason' => $data['reason'] ?? null])->save();
            $this->event($current, $status, $request->user()->id, $data['reason'] ?? null);
            $this->audit->record($request, $request->user(), $companyId, 'expense_claim_decided', 'employee_expenses', $current,
                ['status' => 'SUBMITTED'], ['status' => $status, 'version' => $current->version]);
            $recipient = CompanyUser::query()->where('company_id', $companyId)->where('employee_id', $current->employee_id)
                ->where('is_active', true)->value('user_id');
            if ($recipient !== null && (int) $recipient !== $request->user()->id) {
                $this->notifications->createInApp($companyId, (int) $recipient, 'employee.expense.decided',
                    'Expense claim updated', 'Your expense claim was reviewed.', ['claim_id' => $current->id], '/employee/expenses');
            }

            return $current;
        });

        return response()->json($this->present($model, true));
    }

    private function downloadReceipt(EmployeeExpenseClaim $claim): StreamedResponse
    {
        $document = Document::query()->where('company_id', $claim->company_id)->where('documentable_type', $claim->getMorphClass())
            ->where('documentable_id', $claim->id)->whereKey($claim->receipt_document_id)->firstOrFail();
        if (! Storage::disk($document->storage_disk)->exists($document->storage_key)) {
            throw new PlatformException('FILE_NOT_FOUND', 'The stored receipt is unavailable.', 404);
        }

        return Storage::disk($document->storage_disk)->download($document->storage_key, $document->original_filename,
            ['Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array{string, Employee} */
    private function employeeAccess(Request $request, string $permission, bool $write = false): array
    {
        $companyId = $this->adminAccess($request, $permission);
        $membership = CompanyUser::query()->where('company_id', $companyId)->where('user_id', $request->user()->id)
            ->where('is_active', true)->firstOrFail();
        if ($membership->employee_id === null) {
            throw new PlatformException('EMPLOYEE_IDENTITY_NOT_LINKED', 'No employee identity is linked to this company membership.', 409);
        }
        $employee = Employee::query()->where('company_id', $companyId)->findOrFail($membership->employee_id);
        if ($write && in_array($employee->status, ['resigned', 'terminated'], true)) {
            throw new PlatformException('EMPLOYEE_EXPENSE_INACTIVE', 'Former employees cannot submit or edit expense claims.', 403);
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

    private function owned(string $companyId): Builder
    {
        return EmployeeExpenseClaim::query()->where('company_id', $companyId);
    }

    private function activeCategory(string $companyId, string $categoryId): EmployeeExpenseCategory
    {
        return EmployeeExpenseCategory::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($categoryId);
    }

    /** @return array<string, array<int, string>> */
    private function claimRules(): array
    {
        return ['category_id' => ['required', 'uuid'], 'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'amount_minor' => ['required', 'integer', 'regex:/^[1-9][0-9]{0,14}$/'],
            'expense_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']];
    }

    /** @param array<string, mixed> $data */
    private function payloadHash(array $data): string
    {
        return hash('sha256', json_encode([$data['category_id'], $data['title'], $data['description'] ?? null,
            (string) $data['amount_minor'], $data['expense_date']], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $data */
    private function sameContent(EmployeeExpenseClaim $claim, array $data): bool
    {
        return $claim->category_id === $data['category_id'] && $claim->title === $data['title']
            && $claim->description === ($data['description'] ?? null)
            && $claim->amount_minor === (int) $data['amount_minor']
            && $claim->expense_date?->format('Y-m-d') === $data['expense_date'];
    }

    private function keyHash(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        if (mb_strlen($key) < 8 || mb_strlen($key) > 200) {
            throw new PlatformException('EXPENSE_CLAIM_KEY_REQUIRED', 'An Idempotency-Key of 8 to 200 characters is required.', 422);
        }

        return hash('sha256', $key);
    }

    private function assertVersion(int $current, int $submitted): void
    {
        if ($current !== $submitted) {
            throw new PlatformException('EXPENSE_CLAIM_VERSION_STALE', 'This claim changed; refresh before editing.', 409);
        }
        if ($current >= 4_294_967_295) {
            throw new PlatformException('EXPENSE_CLAIM_VERSION_EXHAUSTED', 'This claim can no longer be changed.', 409);
        }
    }

    private function event(EmployeeExpenseClaim $claim, string $type, int $actorId, ?string $reason = null): void
    {
        EmployeeExpenseClaimEvent::query()->create(['company_id' => $claim->company_id,
            'employee_expense_claim_id' => $claim->id, 'event_type' => $type,
            'claim_version' => $claim->version, 'reason' => $reason, 'actor_id' => $actorId, 'occurred_at' => now()]);
    }

    /** @param list<string> $allowed */
    private function assertOnlyFields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw new PlatformException('EXPENSE_CLAIM_FIELD_FORBIDDEN', 'This field is not employee-editable.', 422);
        }
    }

    private function assertIntegerMoneyInput(Request $request): void
    {
        $amount = $request->input('amount_minor');
        if ($amount !== null && ! is_int($amount) && ! is_string($amount)) {
            throw ValidationException::withMessages(['amount_minor' => 'The amount must be integer minor units.']);
        }
    }

    /** @return array<string, mixed> */
    private function present(EmployeeExpenseClaim $claim, bool $includeEvents = false): array
    {
        $receipt = $claim->receipt_document_id === null ? null : Document::query()->where('company_id', $claim->company_id)
            ->whereKey($claim->receipt_document_id)->first();

        return ['id' => $claim->id, 'employee_id' => $claim->employee_id, 'category_id' => $claim->category_id,
            'title' => $claim->title, 'description' => $claim->description,
            'amount_minor' => $claim->amount_minor, 'currency' => $claim->currency,
            'expense_date' => $claim->expense_date?->format('Y-m-d'), 'status' => $claim->status,
            'version' => $claim->version, 'submitted_at' => $claim->submitted_at?->toIso8601String(),
            'decided_at' => $claim->decided_at?->toIso8601String(), 'decision_reason' => $claim->decision_reason,
            'receipt' => $receipt === null ? null : ['id' => $receipt->id,
                'original_filename' => $receipt->original_filename, 'mime_type' => $receipt->mime_type,
                'size_bytes' => $receipt->size_bytes],
            'events' => $includeEvents ? $claim->events()->orderBy('claim_version')->get()->map(fn (EmployeeExpenseClaimEvent $event): array => [
                'id' => $event->id, 'type' => $event->event_type, 'version' => $event->claim_version,
                'reason' => $event->reason, 'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->all() : null,
            'created_at' => $claim->created_at?->toIso8601String()];
    }
}
