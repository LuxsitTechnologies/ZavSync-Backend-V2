<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformException;
use App\Models\CompanyEntitlement;
use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;

class EntitlementService
{
    /** @var array<string, string> */
    private const ROUTE_MODULES = [
        'accounting' => 'accounting', 'purchases' => 'procurement', 'inventory' => 'inventory',
        'banking' => 'banking', 'planning' => 'budgeting', 'payroll' => 'payroll', 'crm' => 'crm', 'outreach' => 'outreach', 'ai' => 'ai',
    ];

    /** @var array<int, string> */
    private const LEGACY_MODULES = ['accounting', 'invoicing', 'receivables', 'procurement', 'payables', 'inventory', 'banking', 'budgeting', 'payroll', 'crm'];

    /** @return array<int, string> */
    public function enabledModules(string $companyId): array
    {
        return Cache::remember("company:{$companyId}:entitlements", now()->addMinutes(5), function () use ($companyId): array {
            $subscription = Subscription::query()->with('plan.modules')->where('company_id', $companyId)->latest('starts_at')->first();
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
            str_starts_with($path, 'api/v1/accounting/invoices'), str_starts_with($path, 'api/v1/accounting/fbr'), str_starts_with($path, 'api/v1/pakistan-fbr') => 'invoicing',
            str_starts_with($path, 'api/v1/accounting/receivables') => 'receivables',
            str_starts_with($path, 'api/v1/accounting/payables') => 'payables',
            str_starts_with($path, 'api/v1/accounting/inventory') => 'inventory',
            default => self::ROUTE_MODULES[$segment] ?? null,
        };
        if ($module !== null && ! in_array($module, $this->enabledModules($companyId), true)) {
            $subscription = Subscription::query()->where('company_id', $companyId)->latest('starts_at')->first();
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
        $subscription = Subscription::query()->with('plan')->where('company_id', $companyId)->latest('starts_at')->first();
        $limit = CompanyEntitlement::query()->where('company_id', $companyId)->get()->pluck('limits')->filter()
            ->map(fn (array $limits) => $limits[$key] ?? null)->filter(fn ($value) => is_int($value))->last();
        $limit ??= $subscription?->plan->usage_limits[$key] ?? null;
        if (is_int($limit) && $limit >= 0 && $currentUsage + $additionalUsage > $limit * $unitsPerLimit) {
            throw new PlatformException('SUBSCRIPTION_LIMIT_REACHED', "The {$key} subscription limit has been reached.", 409);
        }
    }

    private function subscriptionIsUsable(Subscription $subscription): bool
    {
        if (! in_array($subscription->status, ['TRIALING', 'ACTIVE'], true)) {
            return false;
        }

        return ! ($subscription->status === 'TRIALING' && $subscription->trial_ends_at?->isPast());
    }
}
