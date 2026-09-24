<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payroll\StorePayrollComponentRequest;
use App\Http\Requests\Api\V1\Payroll\StorePayrollStatutoryRuleRequest;
use App\Http\Requests\Api\V1\Payroll\UpdatePayrollComponentRequest;
use App\Http\Resources\PayrollComponentResource;
use App\Models\PayrollComponent;
use App\Models\PayrollStatutoryRule;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollComponentController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeView($request);
        $query = PayrollComponent::query()->where('company_id', $this->companyId($request))->with(['glAccount', 'liabilityAccount']);
        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        return PayrollComponentResource::collection($query->orderBy('type')->orderBy('code')->get());
    }

    public function store(StorePayrollComponentRequest $request): PayrollComponentResource
    {
        $component = PayrollComponent::query()->create([...$request->validated(), 'company_id' => $this->companyId($request), 'created_by' => $request->user()->id]);
        $this->record($request, 'create', $component);

        return new PayrollComponentResource($component->load(['glAccount', 'liabilityAccount']));
    }

    public function show(Request $request, string $component): PayrollComponentResource
    {
        $this->authorizeView($request);

        return new PayrollComponentResource($this->component($request, $component)->load(['glAccount', 'liabilityAccount']));
    }

    public function update(UpdatePayrollComponentRequest $request, string $component): PayrollComponentResource
    {
        $model = $this->component($request, $component);
        $old = $model->toArray();
        $model->update([...$request->validated(), 'updated_by' => $request->user()->id]);
        $this->record($request, 'update', $model, $old);

        return new PayrollComponentResource($model->load(['glAccount', 'liabilityAccount']));
    }

    public function deactivate(Request $request, string $component): PayrollComponentResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'payroll.configure'), 403);
        $model = $this->component($request, $component);
        $old = $model->toArray();
        $model->update(['is_active' => false, 'updated_by' => $request->user()->id]);
        $this->record($request, 'deactivate', $model, $old);

        return new PayrollComponentResource($model);
    }

    public function statutoryRules(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $rules = PayrollStatutoryRule::query()->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $this->companyId($request)))->with('component')->orderByDesc('effective_from')->get();

        return response()->json(['data' => $rules]);
    }

    public function storeStatutoryRule(StorePayrollStatutoryRuleRequest $request): JsonResponse
    {
        $rule = PayrollStatutoryRule::query()->create([...$request->validated(), 'company_id' => $this->companyId($request), 'created_by' => $request->user()->id]);
        $this->audit->record($request, $request->user(), $this->companyId($request), 'create_statutory_rule', 'payroll', $rule, null, $rule->toArray());

        return response()->json(['data' => $rule], 201);
    }

    private function component(Request $request, string $id): PayrollComponent
    {
        return PayrollComponent::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'payroll.view'), 403);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }

    /** @param array<string, mixed>|null $old */
    private function record(Request $request, string $action, PayrollComponent $component, ?array $old = null): void
    {
        $this->audit->record($request, $request->user(), $this->companyId($request), $action, 'payroll_component', $component, $old, $component->toArray());
    }
}
