<?php

namespace App\Http\Controllers\Api\V1\Planning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Planning\StoreBudgetRequest;
use App\Http\Requests\Api\V1\Planning\UpdateBudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use App\Services\AuditService;
use App\Services\Planning\PlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BudgetController extends Controller
{
    public function __construct(private readonly PlanningService $planning, private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);

        return BudgetResource::collection(Budget::query()->where('company_id', $this->companyId($request))->with('fiscalYear')->orderByDesc('created_at')->get());
    }

    public function store(StoreBudgetRequest $request): BudgetResource
    {
        $budget = $this->planning->createBudget($this->companyId($request), $request->user(), $request->validated());
        $this->record($request, 'create', $budget);

        return new BudgetResource($budget);
    }

    public function show(Request $request, string $budget): BudgetResource
    {
        $this->authorizeView($request);

        return new BudgetResource($this->budget($request, $budget)->load(['fiscalYear.periods', 'lines.account', 'lines.period']));
    }

    public function update(UpdateBudgetRequest $request, string $budget): BudgetResource
    {
        $model = $this->budget($request, $budget);
        $old = $model->toArray();
        $model = $this->planning->updateBudget($this->companyId($request), $model, $request->validated());
        $this->record($request, 'update', $model, $old);

        return new BudgetResource($model);
    }

    public function submit(Request $request, string $budget): BudgetResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'budget.submit'), 403);
        $model = $this->planning->submitBudget($this->companyId($request), $request->user(), $this->budget($request, $budget));
        $this->record($request, 'submit', $model);

        return new BudgetResource($model);
    }

    public function approve(Request $request, string $budget): BudgetResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'budget.approve'), 403);
        $model = $this->planning->approveBudget($this->companyId($request), $request->user(), $this->budget($request, $budget));
        $this->record($request, 'approve', $model);

        return new BudgetResource($model);
    }

    public function activate(Request $request, string $budget): BudgetResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'budget.approve'), 403);
        $model = $this->planning->activateBudget($this->companyId($request), $request->user(), $this->budget($request, $budget));
        $this->record($request, 'activate', $model);

        return new BudgetResource($model);
    }

    public function revise(Request $request, string $budget): BudgetResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'budget.manage'), 403);
        $model = $this->planning->reviseBudget($this->companyId($request), $request->user(), $this->budget($request, $budget));
        $this->record($request, 'revise', $model);

        return new BudgetResource($model);
    }

    public function actual(Request $request, string $budget): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json($this->planning->budgetActual($this->companyId($request), $this->budget($request, $budget), $request->query('from'), $request->query('to')));
    }

    private function budget(Request $request, string $id): Budget
    {
        return Budget::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'budget.view'), 403);
    }

    /** @param array<string, mixed>|null $old */
    private function record(Request $request, string $action, Budget $budget, ?array $old = null): void
    {
        $this->audit->record($request, $request->user(), $this->companyId($request), $action, 'budget', $budget, $old, $budget->toArray());
    }
}
