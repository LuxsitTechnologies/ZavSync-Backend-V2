<?php

namespace App\Contracts;

interface BillingGateway
{
    /** @param array<int, string> $providerPriceIds
     * @return array<string, mixed>
     */
    public function createCheckout(string $attemptId, array $providerPriceIds, ?string $customerId,
        string $customerEmail, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function createComponentCheckout(string $attemptId, string $providerPriceId, int $quantity,
        string $customerId, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function retrieveCheckout(string $sessionId): array;

    /** @return array<string, mixed> */
    public function retrieveSubscription(string $subscriptionId): array;

    /** @return array<string, mixed> */
    public function retrieveInvoice(string $invoiceId): array;

    /** @return array<string, mixed> */
    public function retrievePrice(string $priceId): array;

    /** @return array<string, mixed> */
    public function cancelAtPeriodEnd(string $subscriptionId, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function upgradePlan(string $subscriptionId, string $itemId, string $targetPriceId, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function createScheduleFromSubscription(string $subscriptionId, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function schedulePlanChange(string $scheduleId, string $sourcePriceId, string $targetPriceId,
        int $currentPeriodStart, int $currentPeriodEnd, string $targetInterval, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function scheduleComponentQuantity(string $subscriptionId, string $itemId, int $quantity,
        string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function retrievePortalConfiguration(string $configurationId): array;

    /** @return array<string, mixed> */
    public function createPaymentMethodPortalSession(string $customerId, string $configurationId, string $returnUrl): array;
}
