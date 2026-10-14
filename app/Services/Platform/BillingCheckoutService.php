<?php

namespace App\Services\Platform;

use App\Contracts\BillingGateway;
use App\Exceptions\PlatformException;
use App\Models\BillingCataloguePrice;
use App\Models\BillingCheckoutAttempt;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

class BillingCheckoutService
{
    public function __construct(
        private readonly BillingCatalogueService $catalogue,
        private readonly BillingGateway $gateway,
        private readonly AuditService $audit,
    ) {}

    /** @param array<int, string> $addons
     * @return array{attempt_id: string, status: string, checkout_url: ?string}
     */
    public function begin(string $companyId, User $user, string $plan, string $interval, array $addons, string $key): array
    {
        if (config('services.stripe.enabled') !== true || blank(config('services.stripe.secret'))
            || blank(config('services.stripe.webhook_secret')) || blank(config('services.stripe.return_origin'))) {
            throw new PlatformException('BILLING_PROVIDER_NOT_CONFIGURED', 'Stripe billing is not configured.', 503);
        }
        $currency = $this->catalogue->billingJurisdiction($companyId)['currency'];
        sort($addons);
        $hash = hash('sha256', json_encode([$companyId, $plan, $interval, $addons, $currency], JSON_THROW_ON_ERROR));
        $existing = BillingCheckoutAttempt::query()->where('company_id', $companyId)
            ->where('idempotency_key', $key)->first();
        if ($existing !== null && ! hash_equals($existing->payload_hash, $hash)) {
            throw new PlatformException('BILLING_IDEMPOTENCY_CONFLICT', 'The idempotency key belongs to another checkout selection.', 409);
        }
        if ($existing !== null && in_array($existing->status, ['PAYMENT_PENDING', 'ACTIVE'], true)) {
            return $this->replay($existing);
        }
        if ($addons !== []) {
            throw new PlatformException('BILLING_ADDON_SEPARATE_CHECKOUT_REQUIRED',
                'Purchase add-ons separately so their paid periods remain independent of plan changes.', 409);
        }
        $quote = $this->catalogue->quote($companyId, $plan, $interval, $addons);
        $priceIds = [];
        foreach ($quote['lines'] as $line) {
            $price = BillingCataloguePrice::query()->findOrFail($line['catalogue_price_id']);
            if (! is_string($price->provider_price_id) || ! preg_match('/^price_[A-Za-z0-9]+$/', $price->provider_price_id)) {
                throw new PlatformException('BILLING_PRICE_NOT_CONFIGURED', 'A Stripe price mapping is not configured for this billing item.', 503);
            }
            $providerPrice = $this->gateway->retrievePrice($price->provider_price_id);
            if (($providerPrice['id'] ?? null) !== $price->provider_price_id
                || ($providerPrice['active'] ?? null) !== true
                || strtoupper((string) ($providerPrice['currency'] ?? '')) !== $line['currency']
                || ($providerPrice['unit_amount'] ?? null) !== $line['unit_amount_minor']
                || ($providerPrice['recurring']['interval'] ?? null) !== ($line['billing_interval'] === 'annual' ? 'year' : 'month')) {
                throw new PlatformException('BILLING_PRICE_MISMATCH', 'A Stripe price does not match the approved catalogue.', 409);
            }
            $priceIds[] = $price->provider_price_id;
        }
        $settings = CompanySetting::query()->where('company_id', $companyId)->firstOrFail();
        if (blank($settings->email)) {
            throw new PlatformException('BILLING_COMPANY_EMAIL_REQUIRED', 'A company billing email is required.', 409);
        }
        [$attempt, $generation, $claimed] = DB::transaction(function () use ($companyId, $user, $key, $quote, $hash, $priceIds): array {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            if (! $company->is_active) {
                throw new PlatformException('COMPANY_INACTIVE', 'This company is inactive.', 403);
            }
            $attempt = BillingCheckoutAttempt::query()->where('company_id', $companyId)->where('idempotency_key', $key)
                ->lockForUpdate()->first();
            if ($attempt !== null && ! hash_equals($attempt->payload_hash, $hash)) {
                throw new PlatformException('BILLING_IDEMPOTENCY_CONFLICT', 'The idempotency key belongs to another checkout selection.', 409);
            }
            if ($attempt !== null && in_array($attempt->status, ['PAYMENT_PENDING', 'ACTIVE'], true)) {
                return [$attempt, null, false];
            }
            $activePaid = Subscription::query()->where('company_id', $companyId)->where('status', 'ACTIVE')
                ->where('provider', 'stripe')->whereNotNull('provider_reference')->exists();
            if ($activePaid) {
                throw new PlatformException('BILLING_CHANGE_WORKFLOW_REQUIRED', 'An active subscription requires a controlled plan-change workflow.', 409);
            }
            if ($attempt === null && BillingCheckoutAttempt::query()->where('company_id', $companyId)
                ->whereIn('status', ['CREATING', 'PAYMENT_PENDING', 'OUTCOME_UNCERTAIN'])->exists()) {
                throw new PlatformException('BILLING_CHECKOUT_ALREADY_PENDING',
                    'Resolve the existing checkout before starting another payment.', 409);
            }
            if ($attempt !== null && $attempt->status === 'CREATING' && $attempt->claimed_at?->isAfter(now()->subMinutes(2))) {
                throw new PlatformException('BILLING_CHECKOUT_IN_PROGRESS', 'Checkout creation is still in progress.', 409);
            }
            $generation = ($attempt?->claim_generation ?? 0) + 1;
            if ($generation <= 0 || $generation >= PHP_INT_MAX) {
                throw new PlatformException('BILLING_CLAIM_EXHAUSTED', 'Checkout claim generation is exhausted.', 409);
            }
            $attempt ??= BillingCheckoutAttempt::query()->create([
                'company_id' => $companyId, 'idempotency_key' => $key, 'payload_hash' => $hash,
                'operation' => 'NEW_SUBSCRIPTION', 'status' => 'CREATING', 'currency' => $quote['currency'],
                'subtotal_minor' => $quote['subtotal_minor'], 'total_minor' => $quote['total_minor'],
                'price_snapshot' => [...$quote, 'provider_price_ids' => $priceIds], 'requested_by' => $user->id,
            ]);
            $attempt->update(['claim_generation' => $generation, 'claimed_at' => now(), 'status' => 'CREATING']);

            return [$attempt, $generation, true];
        });
        if (! $claimed) {
            return $this->replay($attempt);
        }

        $customerId = DB::table('billing_customers')->where('company_id', $companyId)->value('provider_customer_id');
        try {
            $session = $this->gateway->createCheckout($attempt->id, $priceIds, $customerId,
                $settings->email, 'zavsync-billing:'.$attempt->id);
            $sessionId = $session['id'] ?? null;
            $url = $session['url'] ?? null;
            if (! is_string($sessionId) || ! str_starts_with($sessionId, 'cs_')
                || ($url = $this->safeCheckoutUrl($url)) === null) {
                throw new PlatformException('BILLING_PROVIDER_RESPONSE_INVALID', 'Stripe did not return a safe checkout session.', 503);
            }
        } catch (\Throwable $exception) {
            DB::transaction(function () use ($attempt, $generation): void {
                $current = BillingCheckoutAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                if ($current->claim_generation === $generation && $current->status === 'CREATING') {
                    $current->update(['status' => 'OUTCOME_UNCERTAIN']);
                }
            });
            if ($exception instanceof PlatformException) {
                throw $exception;
            }
            throw new PlatformException('BILLING_PROVIDER_UNAVAILABLE', 'Stripe checkout could not be confirmed. Retry with the same idempotency key.', 503);
        }

        $current = DB::transaction(function () use ($attempt, $generation, $sessionId): BillingCheckoutAttempt {
            $current = BillingCheckoutAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($current->claim_generation !== $generation || $current->status !== 'CREATING') {
                return $current;
            }
            $current->update(['status' => 'PAYMENT_PENDING', 'provider_session_id' => $sessionId]);

            return $current;
        });
        if ($current->provider_session_id !== $sessionId) {
            throw new PlatformException('BILLING_CHECKOUT_SUPERSEDED', 'This checkout claim was superseded.', 409);
        }
        $this->audit->recordOperation($user, $companyId, 'billing_checkout_started', 'platform', $current,
            null, ['attempt_id' => $attempt->id, 'status' => 'PAYMENT_PENDING']);

        return ['attempt_id' => $attempt->id, 'status' => 'PAYMENT_PENDING', 'checkout_url' => $url];
    }

    private function safeCheckoutUrl(mixed $url): ?string
    {
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_HOST) !== 'checkout.stripe.com') {
            return null;
        }

        return $url;
    }

    /** @return array{attempt_id: string, status: string, checkout_url: ?string} */
    private function replay(BillingCheckoutAttempt $attempt): array
    {
        $checkoutUrl = null;
        if ($attempt->status === 'PAYMENT_PENDING' && $attempt->provider_session_id !== null) {
            $session = $this->gateway->retrieveCheckout($attempt->provider_session_id);
            if (($session['id'] ?? null) !== $attempt->provider_session_id
                || ($session['client_reference_id'] ?? null) !== $attempt->id) {
                throw new PlatformException('BILLING_PROVIDER_RESPONSE_INVALID', 'Stripe checkout ownership could not be confirmed.', 503);
            }
            $checkoutUrl = $this->safeCheckoutUrl($session['url'] ?? null);
        }

        return ['attempt_id' => $attempt->id, 'status' => $attempt->status, 'checkout_url' => $checkoutUrl];
    }
}
