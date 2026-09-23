<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\StoreAccountRequest;
use App\Http\Requests\Api\V1\Accounting\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Services\Accounting\AccountHierarchyService;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function __construct(private readonly AuditService $auditService, private readonly AccountHierarchyService $hierarchyService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'accounting.view'), 403);
        $allAccounts = $this->query($request)->orderBy('code')->get();
        $search = $request->string('search')->lower()->toString();
        $type = $request->string('type')->toString();
        $status = $request->string('status')->toString();
        $matched = $allAccounts->filter(function (Account $account) use ($search, $type, $status): bool {
            $matchesSearch = $search === '' || str_contains(mb_strtolower($account->name), $search) || str_contains($account->code, $search);
            $matchesType = $type === '' || $type === 'all' || $account->type === $type;
            $matchesStatus = ! in_array($status, ['active', 'inactive'], true) || $account->is_active === ($status === 'active');

            return $matchesSearch && $matchesType && $matchesStatus;
        });
        $includedIds = $matched->pluck('id')->flip();
        $byId = $allAccounts->keyBy('id');
        foreach ($matched as $account) {
            $parentId = $account->parent_id;
            while ($parentId !== null && $byId->has($parentId)) {
                $includedIds->put($parentId, true);
                $parentId = $byId->get($parentId)->parent_id;
            }
        }
        $accounts = $allAccounts->filter(fn (Account $account): bool => $includedIds->has($account->id));
        $byParent = $accounts->groupBy(fn (Account $account) => $account->parent_id ?? 'root');
        $flattened = collect();
        $walk = function (Collection $nodes, int $depth) use (&$walk, $byParent, $flattened): void {
            foreach ($nodes as $account) {
                $account->depth = $depth;
                $account->has_children = $byParent->has($account->id);
                $flattened->push($account);
                $walk($byParent->get($account->id, collect()), $depth + 1);
            }
        };
        $walk($byParent->get('root', collect()), 0);

        return AccountResource::collection($flattened);
    }

    public function selectable(Request $request): AnonymousResourceCollection
    {
        $request->merge(['status' => 'active']);

        return $this->index($request);
    }

    public function store(StoreAccountRequest $request): AccountResource
    {
        $companyId = $this->companyId($request);
        $this->hierarchyService->validateParent($companyId, $request->validated('type'), $request->validated('parent_id'));
        $normalBalance = in_array($request->validated('type'), ['asset', 'expense'], true) ? 'debit' : 'credit';
        $account = Account::query()->create([...$request->validated(), 'company_id' => $companyId, 'normal_balance' => $normalBalance, 'currency' => 'PKR', 'is_system' => false, 'created_by' => $request->user()->id]);
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'create', 'accounting', $account, null, $account->toArray());

        return new AccountResource($account);
    }

    public function show(Request $request, string $account): AccountResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'accounting.view'), 403);

        return new AccountResource($this->query($request)->findOrFail($account));
    }

    public function update(UpdateAccountRequest $request, string $account): AccountResource
    {
        $model = $this->query($request)->findOrFail($account);
        $oldValues = $model->toArray();
        $this->hierarchyService->validateParent($this->companyId($request), $request->validated('type'), $request->validated('parent_id'), $model);
        $data = $request->validated();
        $data['normal_balance'] = in_array($data['type'], ['asset', 'expense'], true) ? 'debit' : 'credit';
        $hasPostedLines = $model->journalLines()->whereHas('journal', fn (Builder $journal) => $journal->whereIn('status', ['posted', 'reversed']))->exists();
        if ($hasPostedLines && ($data['type'] !== $model->type || ($data['subtype'] ?? null) !== $model->subtype)) {
            throw ValidationException::withMessages(['type' => 'The classification of an account with posted activity cannot be changed.']);
        }
        if ($model->is_system) {
            unset($data['code'], $data['type'], $data['subtype'], $data['normal_balance'], $data['parent_id']);
        }
        if ($hasPostedLines) {
            unset($data['opening_balance'], $data['opening_balance_date']);
        }
        $model->update([...$data, 'updated_by' => $request->user()->id]);
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'update', 'accounting', $model, $oldValues, $model->fresh()->toArray());

        return new AccountResource($model->fresh());
    }

    public function status(Request $request, string $account): AccountResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'accounting.edit'), 403);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $model = $this->query($request)->findOrFail($account);
        abort_if($model->is_system && ! $data['is_active'], 409, 'System accounts cannot be deactivated.');
        $oldValues = $model->toArray();
        $model->update(['is_active' => $data['is_active'], 'updated_by' => $request->user()->id]);
        $this->auditService->record($request, $request->user(), $this->companyId($request), $data['is_active'] ? 'activate' : 'archive', 'accounting', $model, $oldValues, $model->fresh()->toArray());

        return new AccountResource($model);
    }

    private function query(Request $request): Builder
    {
        $postedLines = fn (Builder $builder) => $builder->whereHas('journal', fn (Builder $journal) => $journal->where('status', 'posted'));

        return Account::query()
            ->where('company_id', $this->companyId($request))
            ->withCount(['journalLines as transaction_count' => $postedLines])
            ->withSum(['journalLines as posted_debit' => $postedLines], 'debit')
            ->withSum(['journalLines as posted_credit' => $postedLines], 'credit');
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
