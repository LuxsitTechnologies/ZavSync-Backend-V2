<?php

namespace App\Services\Platform;

use App\Contracts\BillingGateway;
use App\Exceptions\PlatformException;
use Illuminate\Support\Facades\Http;

class StripeBillingGateway implements BillingGateway
{
    /** @param array<int, string> $providerPriceIds
     * @return array<string, mixed>
     */
    public function createCheckout(string $attemptId, array $providerPriceIds, ?string $customerId,
        string $customerEmail, string $idempotencyKey): array
    {
        $origin = rtrim((string) config('services.stripe.return_origin'), '/');
        if (! str_starts_with($origin, 'https://') && ! str_starts_with($origin, 'http://localhost:')) {
            throw new PlatformException('BILLING_RETURN_ORIGIN_INVALID', 'A trusted HTTPS billing return origin is required.', 503);
        }
        $fields = [
            'mode' => 'subscription',
            'client_reference_id' => $attemptId,
            'success_url' => $origin.'/billing?checkout_session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $origin.'/billing?checkout=cancelled',
            'automatic_tax[enabled]' => config('services.stripe.automatic_tax_enabled', false) ? 'true' : 'false',
            'tax_id_collection[enabled]' => 'true',
            'subscription_data[metadata][billing_attempt_id]' => $attemptId,
        ];
        if ($customerId !== null) {
            $fields['customer'] = $customerId;
        } else {
            $fields['customer_email'] = $customerEmail;
        }
        foreach ($providerPriceIds as $index => $priceId) {
            $fields["line_items[{$index}][price]"] = $priceId;
            $fields["line_items[{$index}][quantity]"] = '1';
        }

        return $this->request('post', '/checkout/sessions', $fields, $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function createComponentCheckout(string $attemptId, string $providerPriceId, int $quantity,
        string $customerId, string $idempotencyKey): array
    {
        $origin = rtrim((string) config('services.stripe.return_origin'), '/');
        if (! str_starts_with($origin, 'https://') && ! str_starts_with($origin, 'http://localhost:')) {
            throw new PlatformException('BILLING_RETURN_ORIGIN_INVALID', 'A trusted HTTPS billing return origin is required.', 503);
        }

        return $this->request('post', '/checkout/sessions', [
            'mode' => 'subscription',
            'client_reference_id' => $attemptId,
            'customer' => $customerId,
            'success_url' => $origin.'/billing?checkout_session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $origin.'/billing?checkout=cancelled',
            'automatic_tax[enabled]' => config('services.stripe.automatic_tax_enabled', false) ? 'true' : 'false',
            'tax_id_collection[enabled]' => 'true',
            'subscription_data[metadata][billing_attempt_id]' => $attemptId,
            'line_items[0][price]' => $providerPriceId,
            'line_items[0][quantity]' => (string) $quantity,
        ], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function retrieveCheckout(string $sessionId): array
    {
        return $this->request('get', '/checkout/sessions/'.rawurlencode($sessionId));
    }

    /** @return array<string, mixed> */
    public function retrieveSubscription(string $subscriptionId): array
    {
        return $this->request('get', '/subscriptions/'.rawurlencode($subscriptionId));
    }

    /** @return array<string, mixed> */
    public function retrieveInvoice(string $invoiceId): array
    {
        return $this->request('get', '/invoices/'.rawurlencode($invoiceId));
    }

    /** @return array<string, mixed> */
    public function retrievePrice(string $priceId): array
    {
        return $this->request('get', '/prices/'.rawurlencode($priceId));
    }

    /** @return array<string, mixed> */
    public function cancelAtPeriodEnd(string $subscriptionId, string $idempotencyKey): array
    {
        return $this->request('post', '/subscriptions/'.rawurlencode($subscriptionId), ['cancel_at_period_end' => 'true'], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function upgradePlan(string $subscriptionId, string $itemId, string $targetPriceId, string $idempotencyKey): array
    {
        return $this->request('post', '/subscriptions/'.rawurlencode($subscriptionId), [
            'items[0][id]' => $itemId, 'items[0][price]' => $targetPriceId,
            'items[0][quantity]' => '1', 'billing_cycle_anchor' => 'now',
            'proration_behavior' => 'none', 'payment_behavior' => 'pending_if_incomplete',
        ], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function createScheduleFromSubscription(string $subscriptionId, string $idempotencyKey): array
    {
        return $this->request('post', '/subscription_schedules', [
            'from_subscription' => $subscriptionId,
        ], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function schedulePlanChange(string $scheduleId, string $sourcePriceId, string $targetPriceId,
        int $currentPeriodStart, int $currentPeriodEnd, string $targetInterval, string $idempotencyKey): array
    {
        return $this->request('post', '/subscription_schedules/'.rawurlencode($scheduleId), [
            'end_behavior' => 'release', 'proration_behavior' => 'none',
            'phases[0][start_date]' => (string) $currentPeriodStart,
            'phases[0][end_date]' => (string) $currentPeriodEnd,
            'phases[0][items][0][price]' => $sourcePriceId,
            'phases[0][items][0][quantity]' => '1',
            'phases[1][start_date]' => (string) $currentPeriodEnd,
            'phases[1][items][0][price]' => $targetPriceId,
            'phases[1][items][0][quantity]' => '1',
            'phases[1][duration][interval]' => $targetInterval === 'annual' ? 'year' : 'month',
            'phases[1][duration][interval_count]' => '1',
        ], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function scheduleComponentQuantity(string $subscriptionId, string $itemId, int $quantity,
        string $idempotencyKey): array
    {
        return $this->request('post', '/subscriptions/'.rawurlencode($subscriptionId), [
            'items[0][id]' => $itemId,
            'items[0][quantity]' => (string) $quantity,
            'proration_behavior' => 'none',
        ], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function retrievePortalConfiguration(string $configurationId): array
    {
        return $this->request('get', '/billing_portal/configurations/'.rawurlencode($configurationId));
    }

    /** @return array<string, mixed> */
    public function createPaymentMethodPortalSession(string $customerId, string $configurationId, string $returnUrl): array
    {
        return $this->request('post', '/billing_portal/sessions', [
            'customer' => $customerId, 'configuration' => $configurationId, 'return_url' => $returnUrl,
        ]);
    }

    /** @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $fields = [], ?string $idempotencyKey = null): array
    {
        if (config('services.stripe.enabled') !== true || blank(config('services.stripe.secret'))
            || blank(config('services.stripe.webhook_secret'))) {
            throw new PlatformException('BILLING_PROVIDER_NOT_CONFIGURED', 'Stripe billing is not configured.', 503);
        }
        $request = Http::withToken((string) config('services.stripe.secret'))->acceptJson()
            ->connectTimeout(5)->timeout(15);
        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }
        $url = 'https://api.stripe.com/v1'.$path;
        try {
            $response = $method === 'post' ? $request->asForm()->post($url, $fields) : $request->get($url);
        } catch (\Throwable) {
            throw new PlatformException('BILLING_PROVIDER_UNAVAILABLE', 'Stripe billing could not be reached. The operation may need reconciliation.', 503);
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new PlatformException('BILLING_PROVIDER_UNAVAILABLE', 'Stripe billing did not confirm the operation.', 503);
        }

        return $response->json();
    }
}
