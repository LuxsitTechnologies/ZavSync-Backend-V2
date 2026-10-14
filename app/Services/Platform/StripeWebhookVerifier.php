<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformException;

class StripeWebhookVerifier
{
    /** @return array<string, mixed> */
    public function verify(string $payload, ?string $signatureHeader): array
    {
        $secret = config('services.stripe.webhook_secret');
        if (config('services.stripe.enabled') !== true || ! is_string($secret) || $secret === '') {
            throw new PlatformException('BILLING_WEBHOOK_NOT_CONFIGURED', 'Stripe webhook processing is unavailable.', 503);
        }
        if (strlen($payload) > 1_000_000 || ! is_string($signatureHeader) || strlen($signatureHeader) > 2_000) {
            throw new PlatformException('BILLING_WEBHOOK_INVALID', 'Invalid Stripe webhook request.', 400);
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't' && preg_match('/^[0-9]{1,12}$/', $pair[1])) {
                $timestamp = (int) $pair[1];
            }
            if ($pair[0] === 'v1' && preg_match('/^[a-f0-9]{64}$/i', $pair[1])) {
                $signatures[] = strtolower($pair[1]);
            }
        }
        if ($timestamp === null || abs(now()->timestamp - $timestamp) > 300 || $signatures === []) {
            throw new PlatformException('BILLING_WEBHOOK_INVALID', 'Invalid Stripe webhook request.', 400);
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        if (! collect($signatures)->contains(fn (string $signature): bool => hash_equals($expected, $signature))) {
            throw new PlatformException('BILLING_WEBHOOK_INVALID', 'Invalid Stripe webhook request.', 400);
        }
        try {
            $event = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PlatformException('BILLING_WEBHOOK_INVALID', 'Invalid Stripe webhook request.', 400);
        }
        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! str_starts_with($event['id'], 'evt_')
            || strlen($event['id']) > 255 || ! is_string($event['type'] ?? null) || strlen($event['type']) > 100
            || ! is_int($event['created'] ?? null) || ! is_array($event['data']['object'] ?? null)) {
            throw new PlatformException('BILLING_WEBHOOK_INVALID', 'Invalid Stripe webhook request.', 400);
        }

        return $event;
    }
}
