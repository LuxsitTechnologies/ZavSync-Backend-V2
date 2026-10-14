<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformException;
use App\Models\BillingCheckoutAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingWebhookService
{
    public function __construct(private readonly BillingReconciliationService $reconciliation,
        private readonly BillingSubscriptionLifecycleService $subscriptions) {}

    /** @param array<string, mixed> $event
     * @return array{received: bool, duplicate: bool}
     */
    public function handle(array $event): array
    {
        $object = $event['data']['object'];
        $eventId = $event['id'];
        $eventType = $event['type'];
        $objectId = $object['id'] ?? null;
        if (! is_string($objectId) || strlen($objectId) > 255) {
            throw new PlatformException('BILLING_WEBHOOK_INVALID', 'Invalid Stripe webhook object.', 400);
        }
        DB::table('billing_provider_events')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'provider' => 'stripe', 'provider_event_id' => $eventId,
            'event_type' => $eventType, 'provider_object_id' => $objectId,
            'provider_created_at' => CarbonImmutable::createFromTimestampUTC($event['created']),
            'processing_state' => 'RECEIVED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $generation = DB::transaction(function () use ($eventId, $eventType, $objectId): ?int {
            $record = DB::table('billing_provider_events')->where('provider', 'stripe')
                ->where('provider_event_id', $eventId)->lockForUpdate()->first();
            if ($record !== null && ($record->event_type !== $eventType || $record->provider_object_id !== $objectId)) {
                throw new PlatformException('BILLING_WEBHOOK_INVALID', 'The Stripe event identity changed.', 400);
            }
            if ($record === null || in_array($record->processing_state, ['PROCESSED', 'IGNORED'], true)) {
                return null;
            }
            if ($record->processing_state === 'PROCESSING'
                && CarbonImmutable::parse($record->updated_at)->isAfter(now()->subMinutes(2))) {
                return null;
            }
            if ((int) $record->claim_generation >= PHP_INT_MAX - 1) {
                throw new PlatformException('BILLING_WEBHOOK_CLAIM_EXHAUSTED', 'The webhook claim cannot be safely advanced.', 409);
            }
            $generation = (int) $record->claim_generation + 1;
            DB::table('billing_provider_events')->where('id', $record->id)
                ->update(['processing_state' => 'PROCESSING', 'claim_generation' => $generation, 'updated_at' => now()]);

            return $generation;
        });
        if ($generation === null) {
            return ['received' => true, 'duplicate' => true];
        }
        try {
            DB::transaction(function () use ($event, $eventId, $eventType, $objectId, $generation): void {
                $claim = DB::table('billing_provider_events')->where('provider', 'stripe')
                    ->where('provider_event_id', $eventId)->lockForUpdate()->first();
                if ($claim === null || (int) $claim->claim_generation !== $generation
                    || $claim->processing_state !== 'PROCESSING') {
                    return;
                }

                $companyId = null;
                if (in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
                    $attempt = BillingCheckoutAttempt::query()->where('provider_session_id', $objectId)->first();
                    if ($attempt === null) {
                        throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe checkout is not linked to a company.', 409);
                    }
                    $this->reconciliation->reconcile($attempt->company_id, $attempt->id);
                    $companyId = $attempt->company_id;
                    $state = 'PROCESSED';
                } elseif (in_array($eventType, ['invoice.paid', 'invoice.payment_failed',
                    'customer.subscription.updated', 'customer.subscription.deleted'], true)) {
                    $companyId = $this->subscriptions->apply($event);
                    $state = 'PROCESSED';
                } else {
                    $state = 'IGNORED';
                }
                $this->completeClaim($eventId, $generation, $state, $companyId, null);
            });
        } catch (\Throwable $exception) {
            $this->completeClaim($eventId, $generation, 'FAILED', null,
                $exception instanceof PlatformException ? $exception->errorCode : 'BILLING_PROCESSING_FAILED');
            throw $exception;
        }

        return ['received' => true, 'duplicate' => false];
    }

    private function completeClaim(string $eventId, int $generation, string $state, ?string $companyId, ?string $failureCode): void
    {
        DB::transaction(function () use ($eventId, $generation, $state, $companyId, $failureCode): void {
            $record = DB::table('billing_provider_events')->where('provider', 'stripe')
                ->where('provider_event_id', $eventId)->lockForUpdate()->first();
            if ($record === null || (int) $record->claim_generation !== $generation
                || $record->processing_state !== 'PROCESSING') {
                return;
            }
            DB::table('billing_provider_events')->where('id', $record->id)->update([
                'processing_state' => $state, 'company_id' => $companyId ?? $record->company_id,
                'failure_code' => $failureCode, 'processed_at' => $state === 'FAILED' ? null : now(),
                'updated_at' => now(),
            ]);
        });
    }
}
