<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\CompanyEntitlement;
use App\Models\Plan;
use App\Models\PlatformModule;
use App\Models\Subscription;
use App\Services\AuditService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly EntitlementService $entitlements, private readonly AuditService $audit) {}

    public function show(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.subscription.view');

        return response()->json([
            'subscription' => Subscription::query()->with('plan.modules')->where('company_id', $companyId)->latest('starts_at')->first(),
            'entitlements' => CompanyEntitlement::query()->where('company_id', $companyId)->orderBy('module_key')->get(),
            'enabled_modules' => $this->entitlements->enabledModules($companyId),
            'plans' => Plan::query()->with('modules')->where('is_active', true)->orderBy('id')->get(),
            'modules' => PlatformModule::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.subscription.manage');
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'], 'status' => ['required', 'in:TRIALING,ACTIVE,PAST_DUE,SUSPENDED,CANCELLED,EXPIRED'],
            'billing_interval' => ['required', 'in:monthly,annual'], 'trial_ends_at' => ['nullable', 'date'], 'renews_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date'],
        ]);
        $old = Subscription::query()->where('company_id', $companyId)->latest('starts_at')->first();
        $subscription = Subscription::query()->create([...$data, 'company_id' => $companyId, 'starts_at' => now(), 'cancelled_at' => $data['status'] === 'CANCELLED' ? now() : null]);
        $this->entitlements->forget($companyId);
        $this->audit->record($request, $request->user(), $companyId, 'subscription_updated', 'platform', $subscription, $old?->toArray(), $subscription->toArray());

        return response()->json($subscription->load('plan.modules'));
    }

    public function updateEntitlement(Request $request, string $module): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'platform.subscription.manage');
        abort_unless(PlatformModule::query()->whereKey($module)->exists(), 404);
        $data = $request->validate(['is_enabled' => ['required', 'boolean'], 'limits' => ['nullable', 'array'], 'limits.*' => ['integer', 'min:-1'], 'expires_at' => ['nullable', 'date']]);
        $entitlement = CompanyEntitlement::query()->updateOrCreate(
            ['company_id' => $companyId, 'module_key' => $module],
            [...$data, 'updated_by' => $request->user()->id],
        );
        $this->entitlements->forget($companyId);
        $this->audit->record($request, $request->user(), $companyId, 'entitlement_updated', 'platform', $entitlement, null, $entitlement->toArray());

        return response()->json($entitlement);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        abort_unless($request->user()->is_platform_admin, 403, 'Platform administrator access is required.');
        $this->access->authorize($request->user(), $companyId, 'platform.plans.manage');
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:50', 'unique:plans,code'], 'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'], 'price_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'], 'billing_interval' => ['required', 'in:monthly,annual'],
            'usage_limits' => ['nullable', 'array'], 'usage_limits.*' => ['integer', 'min:-1'], 'features' => ['nullable', 'array'],
            'module_keys' => ['required', 'array'], 'module_keys.*' => ['string', 'exists:platform_modules,key'],
        ]);
        $plan = DB::transaction(function () use ($data): Plan {
            $plan = Plan::query()->create(collect($data)->except('module_keys')->all());
            $plan->modules()->syncWithPivotValues($data['module_keys'], ['is_enabled' => true]);

            return $plan;
        });
        $this->audit->record($request, $request->user(), $companyId, 'plan_created', 'platform', $plan, null, $plan->load('modules')->toArray());

        return response()->json($plan, 201);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}
