<?php

namespace App\Services\Platform;

use App\Contracts\BillingGateway;
use App\Exceptions\PlatformException;
use App\Models\BillingCheckoutAttempt;
use App\Models\BillingComponentSubscription;
use App\Models\BillingPlanChange;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BillingCancellationService
{
    public function __construct(private readonly BillingGateway $gateway, private readonly AuditService $audit,
        private readonly BillingComponentService $components) {}

    public function ensureComponentsScheduledForCancellation(string $companyId, User $actor): void
    {
        if (BillingCheckoutAttempt::query()->where('company_id', $companyId)
            ->whereIn('status', ['CREATING', 'PAYMENT_PENDING', 'OUTCOME_UNCERTAIN'])->exists()
            || BillingPlanChange::query()->where('company_id', $companyId)
                ->whereIn('status', ['CREATING', 'PAYMENT_PENDING', 'OUTCOME_UNCERTAIN', 'SCHEDULED',
                    'APPLIED_CANCELLATION_PENDING'])->exists()) {
            throw new PlatformException('BILLING_CHANGE_REVIEW_REQUIRED', 'Resolve pending billing changes before deactivating this company.', 409);
        }

        $activeComponents = BillingComponentSubscription::query()->where('company_id', $companyId)
            ->where('paid_through_at', '>', now())->whereNull('cancel_at_period_end_at')->get();
        foreach ($activeComponents as $component) {
            $this->components->cancel($companyId, $actor, $component->id);
        }
    }

    /** @return array{subscription_id: string, status: string, cancel_at_period_end_at: string} */
    public function cancelAtPeriodEnd(string $companyId, User $actor): array
    {
        $subscription = Subscription::query()->where('company_id', $companyId)->where('provider', 'stripe')
            ->where('status', 'ACTIVE')->whereNotNull('provider_reference')
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($subscription === null || $subscription->renews_at === null || ! $subscription->renews_at->isFuture()) {
            throw new PlatformException('BILLING_SUBSCRIPTION_NOT_CANCELLABLE', 'No paid subscription is available for period-end cancellation.', 409);
        }
        $this->ensureComponentsScheduledForCancellation($companyId, $actor);
        $customerId = DB::table('billing_customers')->where('company_id', $companyId)->value('provider_customer_id');
        if (! is_string($customerId)) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'The billing customer is not linked to this company.', 409);
        }
        $provider = $this->gateway->cancelAtPeriodEnd($subscription->provider_reference, 'zavsync-cancel:'.$subscription->id);
        $periodEnd = $provider['current_period_end'] ?? ($provider['items']['data'][0]['current_period_end'] ?? null);
        if (($provider['id'] ?? null) !== $subscription->provider_reference
            || ($provider['customer'] ?? null) !== $customerId
            || ($provider['cancel_at_period_end'] ?? null) !== true
            || ($provider['status'] ?? null) !== 'active'
            || ! is_int($periodEnd) || $periodEnd <= now()->timestamp) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe did not confirm period-end cancellation for this company.', 409);
        }
        $endsAt = CarbonImmutable::createFromTimestampUTC($periodEnd);
        DB::transaction(function () use ($companyId, $actor, $subscription, $endsAt): void {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = Subscription::query()->where('company_id', $companyId)->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            if ($current->provider_reference !== $subscription->provider_reference || $current->status !== 'ACTIVE') {
                throw new PlatformException('BILLING_SUBSCRIPTION_CHANGED', 'The subscription changed while cancellation was being confirmed.', 409);
            }
            if ($current->cancel_at_period_end_at === null) {
                $current->update(['cancel_at_period_end_at' => $endsAt]);
                $this->audit->recordOperation($actor, $companyId, 'billing_cancellation_scheduled', 'platform', $current,
                    null, ['subscription_id' => $current->id, 'cancel_at_period_end_at' => $endsAt->toISOString()]);
            }
        });

        return ['subscription_id' => $subscription->id, 'status' => 'CANCEL_AT_PERIOD_END',
            'cancel_at_period_end_at' => $endsAt->toISOString()];
    }
}
