<?php

namespace App\Services\Platform;

use App\Contracts\BillingGateway;
use App\Exceptions\PlatformException;
use App\Models\BillingCheckoutAttempt;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingReconciliationService
{
    public function __construct(
        private readonly BillingGateway $gateway,
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
    ) {}

    /** @return array{attempt_id: string, status: string, subscription_id: ?string} */
    public function reconcile(string $companyId, string $attemptId): array
    {
        $attempt = BillingCheckoutAttempt::query()->where('company_id', $companyId)->findOrFail($attemptId);
        if ($attempt->status === 'ACTIVE') {
            return ['attempt_id' => $attempt->id, 'status' => 'ACTIVE',
                'subscription_id' => Subscription::query()->where('company_id', $companyId)
                    ->where('provider_reference', $attempt->provider_subscription_id)->value('id')];
        }
        if ($attempt->provider_session_id === null) {
            return ['attempt_id' => $attempt->id, 'status' => $attempt->status, 'subscription_id' => null];
        }
        $session = $this->gateway->retrieveCheckout($attempt->provider_session_id);
        $this->assertCheckoutMatches($attempt, $session);
        if (($session['status'] ?? null) !== 'complete' || ($session['payment_status'] ?? null) !== 'paid') {
            return ['attempt_id' => $attempt->id, 'status' => 'PAYMENT_PENDING', 'subscription_id' => null];
        }
        $providerSubscriptionId = $session['subscription'] ?? null;
        $customerId = $session['customer'] ?? null;
        if (! is_string($providerSubscriptionId) || ! str_starts_with($providerSubscriptionId, 'sub_')
            || ! is_string($customerId) || ! str_starts_with($customerId, 'cus_')) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe did not confirm subscription and customer ownership.', 409);
        }
        $providerSubscription = $this->gateway->retrieveSubscription($providerSubscriptionId);
        $this->assertSubscriptionMatches($attempt, $providerSubscription, $providerSubscriptionId, $customerId);
        $periodEnd = $providerSubscription['current_period_end']
            ?? ($providerSubscription['items']['data'][0]['current_period_end'] ?? null);
        if (! is_int($periodEnd) || $periodEnd <= now()->timestamp) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe did not confirm a current paid billing period.', 409);
        }

        $subscription = DB::transaction(function () use ($companyId, $attemptId, $customerId, $providerSubscriptionId, $periodEnd): Subscription {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = BillingCheckoutAttempt::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($attemptId);
            if ($current->status === 'ACTIVE') {
                return Subscription::query()->where('company_id', $companyId)
                    ->where('provider_reference', $providerSubscriptionId)->firstOrFail();
            }
            if ($current->status !== 'PAYMENT_PENDING' || $current->provider_subscription_id !== null) {
                throw new PlatformException('BILLING_CHECKOUT_STATE_CHANGED', 'Checkout state changed before confirmation.', 409);
            }
            if (Subscription::query()->where('company_id', $companyId)->where('status', 'ACTIVE')
                ->where('provider', 'stripe')->whereNotNull('provider_reference')->exists()) {
                throw new PlatformException('BILLING_SUBSCRIPTION_ALREADY_ACTIVE',
                    'A paid subscription is already active for this company. Review the provider payment before retrying.', 409);
            }
            $existingCustomer = DB::table('billing_customers')->where('company_id', $companyId)->first();
            if ($existingCustomer !== null && $existingCustomer->provider_customer_id !== $customerId) {
                throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe customer does not belong to this company.', 409);
            }
            if ($existingCustomer === null) {
                DB::table('billing_customers')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $companyId, 'provider' => 'stripe',
                    'provider_customer_id' => $customerId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $snapshot = $current->price_snapshot;
            $plan = Plan::query()->where('code', $snapshot['plan'])->first();
            if ($plan === null) {
                throw new PlatformException('BILLING_PLAN_NOT_CONFIGURED', 'The selected plan is not configured.', 503);
            }
            $subscription = Subscription::query()->create([
                'company_id' => $companyId, 'plan_id' => $plan->id, 'status' => 'ACTIVE',
                'billing_interval' => $snapshot['billing_interval'], 'billing_currency' => $current->currency,
                'starts_at' => now(), 'renews_at' => CarbonImmutable::createFromTimestampUTC($periodEnd),
                'provider' => 'stripe', 'provider_reference' => $providerSubscriptionId,
            ]);
            foreach ($snapshot['lines'] as $line) {
                DB::table('billing_subscription_items')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $companyId, 'subscription_id' => $subscription->id,
                    'catalogue_price_id' => $line['catalogue_price_id'], 'kind' => $line['kind'], 'code' => $line['code'],
                    'currency' => $line['currency'], 'billing_interval' => $line['billing_interval'],
                    'unit_amount_minor' => $line['unit_amount_minor'], 'quantity' => $line['quantity'],
                    'total_minor' => $line['total_minor'], 'effective_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $current->update(['status' => 'ACTIVE', 'provider_subscription_id' => $providerSubscriptionId,
                'provider_customer_id' => $customerId, 'completed_at' => now()]);
            $this->audit->recordSystemOperation($companyId, 'billing_provider_payment_confirmed', 'platform', $subscription,
                ['checkout_attempt_id' => $attemptId, 'provider' => 'stripe']);

            return $subscription;
        });
        $this->entitlements->forget($companyId);

        return ['attempt_id' => $attemptId, 'status' => 'ACTIVE', 'subscription_id' => $subscription->id];
    }

    /** @param array<string, mixed> $session */
    private function assertCheckoutMatches(BillingCheckoutAttempt $attempt, array $session): void
    {
        if (($session['id'] ?? null) !== $attempt->provider_session_id
            || ($session['client_reference_id'] ?? null) !== $attempt->id
            || strtoupper((string) ($session['currency'] ?? '')) !== $attempt->currency
            || ! is_int($session['amount_subtotal'] ?? null)
            || $session['amount_subtotal'] !== $attempt->subtotal_minor) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe checkout details do not match the company quote.', 409);
        }
    }

    /** @param array<string, mixed> $subscription */
    private function assertSubscriptionMatches(BillingCheckoutAttempt $attempt, array $subscription,
        string $subscriptionId, string $customerId): void
    {
        $items = $subscription['items']['data'] ?? null;
        if (($subscription['id'] ?? null) !== $subscriptionId || ($subscription['customer'] ?? null) !== $customerId
            || ($subscription['status'] ?? null) !== 'active' || ! is_array($items)) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe subscription does not match the paid checkout.', 409);
        }
        $actual = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_array($item['price'] ?? null) || ($item['quantity'] ?? null) !== 1
                || strtoupper((string) ($item['price']['currency'] ?? '')) !== $attempt->currency) {
                throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe subscription item details are invalid.', 409);
            }
            $actual[] = $item['price']['id'] ?? null;
        }
        $expected = $attempt->price_snapshot['provider_price_ids'] ?? [];
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe subscription prices do not match the company quote.', 409);
        }
    }
}
