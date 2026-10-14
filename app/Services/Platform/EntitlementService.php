<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformException;
use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EntitlementService
{
    /** @var array<string, string> */
    private const ROUTE_MODULES = [
        'accounting' => 'accounting', 'purchases' => 'procurement', 'inventory' => 'inventory',
        'banking' => 'banking', 'planning' => 'budgeting', 'payroll' => 'payroll', 'crm' => 'crm', 'outreach' => 'outreach', 'ai' => 'ai',
    ];

    /** @var array<int, string> */
    private const LEGACY_MODULES = ['accounting', 'invoicing', 'fbr', 'receivables', 'procurement', 'payables', 'inventory', 'banking', 'budgeting', 'payroll', 'crm'];

    public function __construct(private readonly BillingCatalogueService $catalogue) {}

    /** @return array<int, string> */
    public function enabledModules(string $companyId): array
    {
        return Cache::remember("company:{$companyId}:entitlements", now()->addMinutes(5), function () use ($companyId): array {
            $company = Company::query()->findOrFail($companyId);
            $subscriptionQuery = Subscription::query()->with('plan.modules')->where('company_id', $companyId);
            $subscription = $company->billing_policy_applies
                ? $subscriptionQuery->orderByDesc('created_at')->orderByDesc('id')->first()
                : $subscriptionQuery->latest('starts_at')->first();
            if ($company->billing_policy_applies) {
                if ($subscription === null || ! $this->subscriptionIsUsable($subscription)) {
                    return [];
                }
                if ($subscription->status === 'TRIALING') {
                    return $this->catalogue->modulesFor('enterprise');
                }
                if ($subscription->provider !== 'stripe' || $subscription->provider_reference === null) {
                    return [];
                }
                $addons = DB::table('billing_subscription_items')
                    ->leftJoin('billing_component_subscriptions as component', 'component.id', '=', 'billing_subscription_items.component_subscription_id')
                    ->where('billing_subscription_items.company_id', $companyId)
                    ->where('billing_subscription_items.subscription_id', $subscription->id)
                    ->where('billing_subscription_items.kind', 'addon')
                    ->where('billing_subscription_items.effective_at', '<=', now())
                    ->where(fn ($query) => $query->whereNull('billing_subscription_items.ends_at')
                        ->orWhere('billing_subscription_items.ends_at', '>', now()))
                    ->where(fn ($query) => $query->whereNull('billing_subscription_items.component_subscription_id')
                        ->orWhere(fn ($paid) => $paid->where('component.company_id', $companyId)
                            ->where('component.status', 'ACTIVE')->where('component.paid_through_at', '>', now())))
                    ->pluck('billing_subscription_items.code')->all();

                return $this->catalogue->modulesFor($subscription->plan->code, $addons);
            }
            $overrides = CompanyEntitlement::query()->where('company_id', $companyId)->get()->keyBy('module_key');

            if ($subscription === null && $overrides->isEmpty()) {
                return self::LEGACY_MODULES;
            }

            if ($subscription !== null && ! $this->subscriptionIsUsable($subscription)) {
                return [];
            }

            $enabled = $subscription?->plan->modules->filter(fn ($module) => (bool) $module->pivot->is_enabled)->pluck('key')->all() ?? [];
            foreach ($overrides as $module => $override) {
                $isCurrent = $override->expires_at === null || $override->expires_at->isFuture();
                if ($override->is_enabled && $isCurrent) {
                    $enabled[] = $module;
                } else {
                    $enabled = array_values(array_diff($enabled, [$module]));
                }
            }

            return array_values(array_unique($enabled));
        });
    }

    public function enforceRequest(string $companyId, string $path): void
    {
        $segment = explode('/', trim($path, '/'))[2] ?? '';
        $module = match (true) {
            str_starts_with($path, 'api/v1/accounting/fbr'), str_starts_with($path, 'api/v1/pakistan-fbr') => 'fbr',
            str_starts_with($path, 'api/v1/accounting/invoices') => 'invoicing',
            str_starts_with($path, 'api/v1/accounting/receivables') => 'receivables',
            str_starts_with($path, 'api/v1/accounting/payables') => 'payables',
            str_starts_with($path, 'api/v1/accounting/inventory') => 'inventory',
            str_starts_with($path, 'api/v1/employee/'), str_starts_with($path, 'api/v1/hrm/') => 'hrm',
            default => self::ROUTE_MODULES[$segment] ?? null,
        };
        if ($module !== null && ! Company::query()->whereKey($companyId)->value('billing_policy_applies')) {
            $module = match ($module) {
                'fbr' => 'invoicing',
                'hrm' => 'payroll',
                default => $module,
            };
        }
        if ($module !== null && ! in_array($module, $this->enabledModules($companyId), true)) {
            $subscription = Subscription::query()->where('company_id', $companyId)
                ->orderByDesc('created_at')->orderByDesc('id')->first();
            $code = $subscription !== null && ! $this->subscriptionIsUsable($subscription) ? 'SUBSCRIPTION_INACTIVE' : 'MODULE_NOT_ENTITLED';
            throw new PlatformException($code, 'This module is not available for the active company.', 403);
        }
    }

    public function forget(string $companyId): void
    {
        Cache::forget("company:{$companyId}:entitlements");
    }

    public function assertWithinLimit(string $companyId, string $key, int $currentUsage, int $additionalUsage = 1, int $unitsPerLimit = 1): void
    {
        if ($currentUsage < 0 || $additionalUsage < 0 || $currentUsage > PHP_INT_MAX - $additionalUsage) {
            throw new PlatformException('SUBSCRIPTION_USAGE_INVALID', 'Subscription usage is outside the supported range.', 409);
        }
        $company = Company::query()->findOrFail($companyId);
        if ($company->billing_policy_applies && in_array($key, ['daily_ai_requests', 'monthly_ai_tokens',
            'monthly_ai_cost_minor', 'knowledge_sources', 'knowledge_chunks'], true)) {
            if (! in_array('ai', $this->enabledModules($companyId), true)) {
                throw new PlatformException('MODULE_NOT_ENTITLED', 'AI is not available for this company.', 403);
            }
            if ($key === 'monthly_ai_tokens') {
                $allowance = (int) config('services.billing.ai_monthly_token_allowance');
                if ($allowance < 1 || $currentUsage + $additionalUsage > $allowance) {
                    throw new PlatformException('SUBSCRIPTION_LIMIT_REACHED', 'The monthly AI token allowance has been reached.', 409);
                }
            }

            return;
        }
        if ($company->billing_policy_applies && in_array($key, ['users', 'employees', 'document_storage_mb'], true)) {
            $commercialSubscription = Subscription::query()->with('plan')->where('company_id', $companyId)
                ->orderByDesc('created_at')->orderByDesc('id')->first();
            if ($commercialSubscription === null || ! $this->subscriptionIsUsable($commercialSubscription)) {
                throw new PlatformException('SUBSCRIPTION_INACTIVE', 'An active trial or paid subscription is required.', 403);
            }
            $planCode = $commercialSubscription->status === 'TRIALING' ? 'professional' : $commercialSubscription->plan->code;
            $limits = $this->catalogue->includedLimits($planCode);
            $limit = $key === 'document_storage_mb' ? $limits['storage_mb'] : $limits[$key];
            if ($key !== 'document_storage_mb') {
                $paidSeats = $this->paidSeatQuantity($companyId, $commercialSubscription, $key);
                if ($limit > PHP_INT_MAX - $paidSeats) {
                    throw new PlatformException('SUBSCRIPTION_USAGE_INVALID', 'Subscription capacity is outside the supported range.', 409);
                }
                $limit += $paidSeats;
            }
            if ($currentUsage + $additionalUsage > $limit * $unitsPerLimit) {
                throw new PlatformException('SUBSCRIPTION_LIMIT_REACHED', "The {$key} subscription limit has been reached.", 409);
            }

            return;
        }
        $subscription = Subscription::query()->with('plan')->where('company_id', $companyId)->latest('starts_at')->first();
        $limit = CompanyEntitlement::query()->where('company_id', $companyId)->get()->pluck('limits')->filter()
            ->map(fn (array $limits) => $limits[$key] ?? null)->filter(fn ($value) => is_int($value))->last();
        $limit ??= $subscription?->plan->usage_limits[$key] ?? null;
        if (is_int($limit) && $limit >= 0 && $currentUsage + $additionalUsage > $limit * $unitsPerLimit) {
            throw new PlatformException('SUBSCRIPTION_LIMIT_REACHED', "The {$key} subscription limit has been reached.", 409);
        }
    }

    public function paidSeatQuantity(string $companyId, Subscription $subscription, string $key): int
    {
        if (! in_array($key, ['users', 'employees'], true) || $subscription->company_id !== $companyId
            || $subscription->provider !== 'stripe' || $subscription->provider_reference === null
            || ! in_array($subscription->status, ['ACTIVE', 'PAST_DUE_GRACE'], true)
            || ! $this->subscriptionIsUsable($subscription)) {
            return 0;
        }

        return (int) DB::table('billing_subscription_items')
            ->leftJoin('billing_component_subscriptions as component', 'component.id', '=', 'billing_subscription_items.component_subscription_id')
            ->where('billing_subscription_items.company_id', $companyId)
            ->where('billing_subscription_items.subscription_id', $subscription->id)
            ->where('billing_subscription_items.kind', 'seat')
            ->where('billing_subscription_items.code', $key === 'users' ? 'user_overage' : 'employee_overage')
            ->where('billing_subscription_items.billing_interval', 'monthly')
            ->where('billing_subscription_items.effective_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('billing_subscription_items.ends_at')
                ->orWhere('billing_subscription_items.ends_at', '>', now()))
            ->where(fn ($query) => $query->whereNull('billing_subscription_items.component_subscription_id')
                ->orWhere(fn ($paid) => $paid->where('component.company_id', $companyId)
                    ->where('component.status', 'ACTIVE')->where('component.paid_through_at', '>', now())))
            ->sum('billing_subscription_items.quantity');
    }

    private function subscriptionIsUsable(Subscription $subscription): bool
    {
        if (! in_array($subscription->status, ['TRIALING', 'ACTIVE', 'PAST_DUE_GRACE'], true)) {
            return false;
        }

        if ($subscription->status === 'TRIALING') {
            return $subscription->trial_ends_at?->isFuture() ?? false;
        }
        if ($subscription->status === 'PAST_DUE_GRACE') {
            return $subscription->grace_ends_at?->isFuture() ?? false;
        }

        return $subscription->provider === 'stripe' && $subscription->provider_reference !== null
            ? ($subscription->renews_at?->isFuture() ?? false) : true;
    }
}
