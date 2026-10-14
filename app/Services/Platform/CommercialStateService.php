<?php

namespace App\Services\Platform;

use App\Models\BillingCheckoutAttempt;
use App\Models\Company;
use App\Models\Subscription;

class CommercialStateService
{
    /** @return array{state: string, subscription_id: ?string, trial_ends_at: ?string, grace_ends_at: ?string, paid_period_ends_at: ?string} */
    public function describe(Company $company): array
    {
        $subscription = Subscription::query()->where('company_id', $company->id)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $pending = BillingCheckoutAttempt::query()->where('company_id', $company->id)
            ->whereIn('status', ['CREATING', 'PAYMENT_PENDING', 'OUTCOME_UNCERTAIN'])->exists();
        $state = match (true) {
            ! $company->is_active => 'DEACTIVATED',
            $company->approval_status !== 'APPROVED' => 'PENDING_APPROVAL',
            $subscription?->status === 'TRIALING' && $subscription->trial_ends_at?->isFuture() => 'TRIAL',
            $subscription?->provider === 'stripe' && $subscription->provider_reference !== null
                && $subscription->status === 'ACTIVE' && $subscription->renews_at?->isFuture()
                && $subscription->cancel_at_period_end_at?->isFuture() => 'CANCEL_AT_PERIOD_END',
            $subscription?->provider === 'stripe' && $subscription->provider_reference !== null
                && $subscription->status === 'ACTIVE' && $subscription->renews_at?->isFuture()
                && ($subscription->ends_at === null || $subscription->ends_at->isFuture()) => 'ACTIVE',
            $subscription?->provider === 'stripe' && $subscription->provider_reference !== null
                && $subscription->status === 'PAST_DUE_GRACE' && $subscription->grace_ends_at?->isFuture() => 'PAST_DUE_GRACE',
            $pending => 'PAYMENT_PENDING',
            default => 'READ_ONLY',
        };

        return [
            'state' => $state,
            'subscription_id' => $subscription?->id,
            'trial_ends_at' => $subscription?->trial_ends_at?->toISOString(),
            'grace_ends_at' => $subscription?->grace_ends_at?->toISOString(),
            'paid_period_ends_at' => $subscription?->renews_at?->toISOString(),
        ];
    }
}
