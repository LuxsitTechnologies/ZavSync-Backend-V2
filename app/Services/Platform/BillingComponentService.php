<?php

namespace App\Services\Platform;

use App\Contracts\BillingGateway;
use App\Exceptions\PlatformException;
use App\Models\BillingCataloguePrice;
use App\Models\BillingCheckoutAttempt;
use App\Models\BillingComponentSubscription;
use App\Models\BillingPlanChange;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingComponentService
{
    public function __construct(
        private readonly BillingCatalogueService $catalogue,
        private readonly BillingGateway $gateway,
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
    ) {}

    /** @return array{attempt_id: string, status: string, checkout_url: ?string} */
    public function begin(string $companyId, User $user, string $kind, string $code, int $quantity, string $key): array
    {
        if (config('services.stripe.enabled') !== true || blank(config('services.stripe.secret'))
            || blank(config('services.stripe.webhook_secret')) || blank(config('services.stripe.return_origin'))) {
            throw new PlatformException('BILLING_PROVIDER_NOT_CONFIGURED', 'Stripe billing is not configured.', 503);
        }
        if (($kind === 'addon' && (! in_array($code, ['fbr', 'crm', 'inventory', 'ai', 'hrm_payroll'], true) || $quantity !== 1))
            || ($kind === 'seat' && (! in_array($code, ['user_overage', 'employee_overage'], true) || $quantity < 1 || $quantity > 10_000))) {
            throw new PlatformException('BILLING_SELECTION_INVALID', 'The billing component selection is invalid.', 422);
        }
        $prior = BillingCheckoutAttempt::query()->where('company_id', $companyId)->where('idempotency_key', $key)->first();
        $priorLine = $prior?->price_snapshot['line'] ?? null;
        if ($prior !== null && ($prior->operation !== 'COMPONENT_CHECKOUT' || ! is_array($priorLine)
            || ($priorLine['kind'] ?? null) !== $kind || ($priorLine['code'] ?? null) !== $code
            || ($priorLine['quantity'] ?? null) !== $quantity)) {
            throw new PlatformException('BILLING_IDEMPOTENCY_CONFLICT', 'The idempotency key belongs to another billing selection.', 409);
        }
        if ($prior !== null && in_array($prior->status, ['PAYMENT_PENDING', 'ACTIVE'], true)) {
            return $this->replay($prior);
        }
        $subscription = $this->paidBaseSubscription($companyId);
        if ($kind === 'addon' && in_array($code, $this->catalogue->includedAddons($subscription->plan->code), true)) {
            throw new PlatformException('BILLING_ADDON_INCLUDED', 'The add-on is already included in this plan.', 409);
        }
        if ($kind === 'seat' && $code === 'employee_overage'
            && ! in_array('hrm', $this->entitlements->enabledModules($companyId), true)) {
            throw new PlatformException('MODULE_NOT_ENTITLED', 'HRM is required before purchasing employee capacity.', 403);
        }
        $currency = $this->catalogue->billingJurisdiction($companyId)['currency'];
        if ($subscription->billing_currency !== $currency) {
            throw new PlatformException('BILLING_CURRENCY_MISMATCH', 'The active subscription currency differs from the company billing currency.', 409);
        }
        $interval = $kind === 'seat' ? 'monthly' : $subscription->billing_interval;
        if ($prior !== null && ($prior->price_snapshot['base_subscription_id'] ?? null) !== $subscription->id) {
            throw new PlatformException('BILLING_SUBSCRIPTION_CHANGED', 'The base subscription changed before checkout recovery.', 409);
        }
        $price = $prior === null
            ? $this->catalogue->price($kind, $code, $currency, $interval)
            : BillingCataloguePrice::query()->findOrFail($priorLine['catalogue_price_id']);
        $line = $priorLine ?? $this->catalogue->line($price, $quantity);
        $hash = hash('sha256', json_encode([$companyId, $subscription->id, $line], JSON_THROW_ON_ERROR));
        $existing = BillingCheckoutAttempt::query()->where('company_id', $companyId)->where('idempotency_key', $key)->first();
        if ($existing !== null && ! hash_equals($existing->payload_hash, $hash)) {
            throw new PlatformException('BILLING_IDEMPOTENCY_CONFLICT', 'The idempotency key belongs to another billing selection.', 409);
        }
        if ($existing !== null && in_array($existing->status, ['PAYMENT_PENDING', 'ACTIVE'], true)) {
            return $this->replay($existing);
        }
        if ($kind === 'addon' && BillingComponentSubscription::query()->where('company_id', $companyId)
            ->where('subscription_id', $subscription->id)->where('kind', $kind)->where('code', $code)
            ->where('paid_through_at', '>', now())->exists()) {
            throw new PlatformException('BILLING_COMPONENT_ALREADY_ACTIVE', 'This billing component is already paid for.', 409);
        }
        if (! is_string($price->provider_price_id) || ! preg_match('/^price_[A-Za-z0-9]+$/', $price->provider_price_id)) {
            throw new PlatformException('BILLING_PRICE_NOT_CONFIGURED', 'A Stripe price mapping is not configured for this billing item.', 503);
        }
        $providerPrice = $this->gateway->retrievePrice($price->provider_price_id);
        if (($providerPrice['id'] ?? null) !== $price->provider_price_id
            || ($providerPrice['active'] ?? null) !== true
            || strtoupper((string) ($providerPrice['currency'] ?? '')) !== $currency
            || ($providerPrice['unit_amount'] ?? null) !== $line['unit_amount_minor']
            || ($providerPrice['recurring']['interval'] ?? null) !== ($interval === 'annual' ? 'year' : 'month')) {
            throw new PlatformException('BILLING_PRICE_MISMATCH', 'The Stripe price does not match the approved catalogue.', 409);
        }
        $customerId = DB::table('billing_customers')->where('company_id', $companyId)->where('provider', 'stripe')
            ->value('provider_customer_id');
        if (! is_string($customerId) || ! preg_match('/^cus_[A-Za-z0-9]+$/', $customerId)) {
            throw new PlatformException('BILLING_CUSTOMER_UNAVAILABLE', 'This company has no linked Stripe billing customer.', 409);
        }

        [$attempt, $generation, $claimed] = DB::transaction(function () use ($companyId, $user, $subscription, $key, $hash, $line, $price, $kind, $code): array {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            if ($this->paidBaseSubscription($companyId)->id !== $subscription->id) {
                throw new PlatformException('BILLING_SUBSCRIPTION_CHANGED', 'The active subscription changed. Request a new quote.', 409);
            }
            if (BillingPlanChange::query()->where('company_id', $companyId)
                ->where('subscription_id', $subscription->id)
                ->whereIn('status', ['CREATING', 'OUTCOME_UNCERTAIN', 'PAYMENT_PENDING',
                    'SCHEDULED', 'APPLIED_CANCELLATION_PENDING'])->exists()) {
                throw new PlatformException('BILLING_PLAN_CHANGE_PENDING', 'Resolve the plan change before purchasing components.', 409);
            }
            $attempt = BillingCheckoutAttempt::query()->where('company_id', $companyId)->where('idempotency_key', $key)
                ->lockForUpdate()->first();
            if ($attempt !== null && ! hash_equals($attempt->payload_hash, $hash)) {
                throw new PlatformException('BILLING_IDEMPOTENCY_CONFLICT', 'The idempotency key belongs to another billing selection.', 409);
            }
            if ($attempt !== null && in_array($attempt->status, ['PAYMENT_PENDING', 'ACTIVE'], true)) {
                return [$attempt, null, false];
            }
            if ($kind === 'addon' && BillingComponentSubscription::query()->where('company_id', $companyId)->where('subscription_id', $subscription->id)
                ->where('kind', $kind)->where('code', $code)->where('paid_through_at', '>', now())->exists()) {
                throw new PlatformException('BILLING_COMPONENT_ALREADY_ACTIVE', 'This billing component is already paid for.', 409);
            }
            if ($attempt === null && BillingCheckoutAttempt::query()->where('company_id', $companyId)
                ->where('operation', 'COMPONENT_CHECKOUT')->whereIn('status', ['CREATING', 'PAYMENT_PENDING', 'OUTCOME_UNCERTAIN'])
                ->where('price_snapshot->line->kind', $kind)->where('price_snapshot->line->code', $code)->exists()) {
                throw new PlatformException('BILLING_COMPONENT_ALREADY_PENDING', 'Resolve the pending component purchase first.', 409);
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
                'operation' => 'COMPONENT_CHECKOUT', 'status' => 'CREATING', 'currency' => $line['currency'],
                'subtotal_minor' => $line['total_minor'], 'total_minor' => $line['total_minor'],
                'price_snapshot' => ['base_subscription_id' => $subscription->id, 'line' => $line,
                    'provider_price_ids' => [$price->provider_price_id]], 'requested_by' => $user->id,
            ]);
            $attempt->update(['claim_generation' => $generation, 'claimed_at' => now(), 'status' => 'CREATING']);

            return [$attempt, $generation, true];
        });
        if (! $claimed) {
            return $this->replay($attempt);
        }
        try {
            $session = $this->gateway->createComponentCheckout($attempt->id, $price->provider_price_id,
                $quantity, $customerId, 'zavsync-component:'.$attempt->id);
            $sessionId = $session['id'] ?? null;
            $url = $this->safeCheckoutUrl($session['url'] ?? null);
            if (! is_string($sessionId) || ! str_starts_with($sessionId, 'cs_') || $url === null) {
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
            if ($current->claim_generation === $generation && $current->status === 'CREATING') {
                $current->update(['status' => 'PAYMENT_PENDING', 'provider_session_id' => $sessionId]);
            }

            return $current;
        });
        if ($current->provider_session_id !== $sessionId) {
            throw new PlatformException('BILLING_CHECKOUT_SUPERSEDED', 'This checkout claim was superseded.', 409);
        }
        $this->audit->recordOperation($user, $companyId, 'billing_component_checkout_started', 'platform', $current,
            null, ['attempt_id' => $current->id, 'kind' => $kind, 'code' => $code]);

        return ['attempt_id' => $current->id, 'status' => 'PAYMENT_PENDING', 'checkout_url' => $url];
    }

    /** @return array{attempt_id: string, status: string, subscription_id: ?string} */
    public function reconcile(string $companyId, string $attemptId): array
    {
        $attempt = BillingCheckoutAttempt::query()->where('company_id', $companyId)
            ->where('operation', 'COMPONENT_CHECKOUT')->findOrFail($attemptId);
        if ($attempt->status === 'ACTIVE') {
            return ['attempt_id' => $attempt->id, 'status' => 'ACTIVE',
                'subscription_id' => BillingComponentSubscription::query()->where('company_id', $companyId)
                    ->where('checkout_attempt_id', $attemptId)->value('id')];
        }
        if ($attempt->provider_session_id === null) {
            return ['attempt_id' => $attempt->id, 'status' => $attempt->status, 'subscription_id' => null];
        }
        $session = $this->gateway->retrieveCheckout($attempt->provider_session_id);
        if (($session['id'] ?? null) !== $attempt->provider_session_id
            || ($session['client_reference_id'] ?? null) !== $attempt->id
            || strtoupper((string) ($session['currency'] ?? '')) !== $attempt->currency
            || ($session['amount_subtotal'] ?? null) !== $attempt->subtotal_minor) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe checkout does not match the component quote.', 409);
        }
        if (($session['status'] ?? null) !== 'complete' || ($session['payment_status'] ?? null) !== 'paid') {
            return ['attempt_id' => $attempt->id, 'status' => 'PAYMENT_PENDING', 'subscription_id' => null];
        }
        $providerSubscriptionId = $session['subscription'] ?? null;
        $customerId = $session['customer'] ?? null;
        if (! is_string($providerSubscriptionId) || ! str_starts_with($providerSubscriptionId, 'sub_')
            || ! is_string($customerId) || ! str_starts_with($customerId, 'cus_')
            || DB::table('billing_customers')->where('company_id', $companyId)->where('provider', 'stripe')
                ->value('provider_customer_id') !== $customerId) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe component ownership is not established.', 409);
        }
        $provider = $this->gateway->retrieveSubscription($providerSubscriptionId);
        $line = $attempt->price_snapshot['line'] ?? null;
        $items = $provider['items']['data'] ?? null;
        if (! is_array($line) || ($provider['id'] ?? null) !== $providerSubscriptionId
            || ($provider['customer'] ?? null) !== $customerId || ($provider['status'] ?? null) !== 'active'
            || ! is_array($items) || count($items) !== 1
            || ($items[0]['price']['id'] ?? null) !== ($attempt->price_snapshot['provider_price_ids'][0] ?? null)
            || strtoupper((string) ($items[0]['price']['currency'] ?? '')) !== $attempt->currency
            || ($items[0]['quantity'] ?? null) !== $line['quantity']) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe subscription does not match the component purchase.', 409);
        }
        $periodEnd = $provider['current_period_end'] ?? ($items[0]['current_period_end'] ?? null);
        if (! is_int($periodEnd) || $periodEnd <= now()->timestamp) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe did not confirm a current paid component period.', 409);
        }
        $component = DB::transaction(function () use ($companyId, $attemptId, $providerSubscriptionId, $customerId, $periodEnd): BillingComponentSubscription {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = BillingCheckoutAttempt::query()->where('company_id', $companyId)->where('operation', 'COMPONENT_CHECKOUT')
                ->lockForUpdate()->findOrFail($attemptId);
            if ($current->status === 'ACTIVE') {
                return BillingComponentSubscription::query()->where('company_id', $companyId)
                    ->where('checkout_attempt_id', $attemptId)->firstOrFail();
            }
            if ($current->status !== 'PAYMENT_PENDING' || $current->provider_subscription_id !== null) {
                throw new PlatformException('BILLING_CHECKOUT_STATE_CHANGED', 'Component checkout state changed before confirmation.', 409);
            }
            $line = $current->price_snapshot['line'];
            $baseId = $current->price_snapshot['base_subscription_id'];
            $base = $this->paidBaseSubscription($companyId);
            if ($base->id !== $baseId || $base->billing_currency !== $current->currency) {
                throw new PlatformException('BILLING_SUBSCRIPTION_CHANGED', 'The base subscription changed before component confirmation.', 409);
            }
            if ($line['kind'] === 'addon' && BillingComponentSubscription::query()->where('company_id', $companyId)
                ->where('subscription_id', $baseId)->where('kind', $line['kind'])->where('code', $line['code'])
                ->where('paid_through_at', '>', now())->exists()) {
                throw new PlatformException('BILLING_COMPONENT_ALREADY_ACTIVE', 'This component is already paid for.', 409);
            }
            $component = BillingComponentSubscription::query()->create([
                'company_id' => $companyId, 'subscription_id' => $baseId,
                'catalogue_price_id' => $line['catalogue_price_id'], 'checkout_attempt_id' => $attemptId,
                'kind' => $line['kind'], 'code' => $line['code'], 'currency' => $current->currency,
                'billing_interval' => $line['billing_interval'], 'quantity' => $line['quantity'],
                'status' => 'ACTIVE', 'provider_subscription_id' => $providerSubscriptionId,
                'paid_through_at' => CarbonImmutable::createFromTimestampUTC($periodEnd),
            ]);
            DB::table('billing_subscription_items')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId, 'subscription_id' => $baseId,
                'component_subscription_id' => $component->id, 'catalogue_price_id' => $line['catalogue_price_id'],
                'kind' => $line['kind'], 'code' => $line['code'], 'currency' => $current->currency,
                'billing_interval' => $line['billing_interval'], 'unit_amount_minor' => $line['unit_amount_minor'],
                'quantity' => $line['quantity'], 'total_minor' => $line['total_minor'], 'effective_at' => now(),
                'ends_at' => CarbonImmutable::createFromTimestampUTC($periodEnd), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $current->update(['status' => 'ACTIVE', 'provider_subscription_id' => $providerSubscriptionId,
                'provider_customer_id' => $customerId, 'completed_at' => now()]);
            $this->audit->recordSystemOperation($companyId, 'billing_component_payment_confirmed', 'platform', $component,
                ['checkout_attempt_id' => $attemptId, 'kind' => $component->kind, 'code' => $component->code]);

            return $component;
        });
        $this->entitlements->forget($companyId);

        return ['attempt_id' => $attemptId, 'status' => 'ACTIVE', 'subscription_id' => $component->id];
    }

    /** @return array{component_id: string, status: string, cancel_at_period_end_at: ?string} */
    public function cancel(string $companyId, User $user, string $componentId): array
    {
        $component = BillingComponentSubscription::query()->where('company_id', $companyId)->findOrFail($componentId);
        if ($component->cancel_at_period_end_at !== null) {
            return ['component_id' => $component->id, 'status' => $component->status,
                'cancel_at_period_end_at' => $component->cancel_at_period_end_at->toISOString()];
        }
        if ($component->status !== 'ACTIVE' || ! $component->paid_through_at->isFuture()) {
            throw new PlatformException('BILLING_COMPONENT_INACTIVE', 'This component has no current paid term to cancel.', 409);
        }
        $provider = $this->gateway->cancelAtPeriodEnd($component->provider_subscription_id,
            'zavsync-component-cancel:'.$component->id);
        $periodEnd = $provider['current_period_end'] ?? ($provider['items']['data'][0]['current_period_end'] ?? null);
        $customerId = DB::table('billing_customers')->where('company_id', $companyId)->where('provider', 'stripe')
            ->value('provider_customer_id');
        if (($provider['id'] ?? null) !== $component->provider_subscription_id
            || ($provider['customer'] ?? null) !== $customerId
            || ($provider['status'] ?? null) !== 'active'
            || ($provider['cancel_at_period_end'] ?? null) !== true
            || ! is_int($periodEnd)
            || $periodEnd > $component->paid_through_at->timestamp) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe did not confirm the component cancellation boundary.', 409);
        }
        $current = DB::transaction(function () use ($companyId, $componentId, $user, $periodEnd): BillingComponentSubscription {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = BillingComponentSubscription::query()->where('company_id', $companyId)
                ->lockForUpdate()->findOrFail($componentId);
            if ($current->cancel_at_period_end_at === null) {
                $current->update(['cancel_at_period_end_at' => CarbonImmutable::createFromTimestampUTC($periodEnd)]);
                $this->audit->recordOperation($user, $companyId, 'billing_component_cancellation_scheduled', 'platform', $current,
                    null, ['component_id' => $current->id, 'paid_through_at' => $current->paid_through_at->toISOString()]);
            }

            return $current;
        });

        return ['component_id' => $current->id, 'status' => $current->status,
            'cancel_at_period_end_at' => $current->cancel_at_period_end_at?->toISOString()];
    }

    /** @return array{component_id: string, status: string, current_quantity: int, scheduled_quantity: int, effective_at: string} */
    public function reduceSeats(string $companyId, User $user, string $componentId, int $targetQuantity, string $key): array
    {
        $component = BillingComponentSubscription::query()->where('company_id', $companyId)->findOrFail($componentId);
        $existing = DB::table('billing_component_quantity_changes')->where('company_id', $companyId)
            ->where('idempotency_key', $key)->first();
        if ($existing !== null && ($existing->component_subscription_id !== $componentId
            || (int) $existing->target_quantity !== $targetQuantity)) {
            throw new PlatformException('BILLING_IDEMPOTENCY_CONFLICT', 'The idempotency key belongs to another seat change.', 409);
        }
        if ($existing !== null && in_array($existing->status, ['SCHEDULED', 'APPLIED'], true)) {
            return $this->seatReductionResponse($component, $targetQuantity, $existing->status);
        }
        if ($component->kind !== 'seat' || $targetQuantity < 1 || $targetQuantity >= $component->quantity
            || $component->status !== 'ACTIVE' || ! $component->paid_through_at->isFuture()
            || $component->cancel_at_period_end_at !== null) {
            throw new PlatformException('BILLING_SEAT_REDUCTION_INVALID', 'Select a smaller positive quantity for an active seat purchase.', 409);
        }
        $provider = $this->gateway->retrieveSubscription($component->provider_subscription_id);
        $item = $provider['items']['data'][0] ?? null;
        $customerId = DB::table('billing_customers')->where('company_id', $companyId)->where('provider', 'stripe')
            ->value('provider_customer_id');
        if (($provider['id'] ?? null) !== $component->provider_subscription_id
            || ($provider['customer'] ?? null) !== $customerId || ($provider['status'] ?? null) !== 'active'
            || ! is_array($item) || count($provider['items']['data'] ?? []) !== 1
            || ! is_string($item['id'] ?? null) || ! str_starts_with($item['id'], 'si_')
            || ($item['price']['id'] ?? null) !== $component->cataloguePrice->provider_price_id
            || ! in_array(($item['quantity'] ?? null), [$component->quantity, $targetQuantity], true)) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe seat quantity does not match this company.', 409);
        }
        [$id, $generation] = DB::transaction(function () use ($companyId, $user, $component, $targetQuantity, $key): array {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = BillingComponentSubscription::query()->where('company_id', $companyId)
                ->lockForUpdate()->findOrFail($component->id);
            if ($current->quantity !== $component->quantity || $current->scheduled_quantity !== null) {
                throw new PlatformException('BILLING_SEAT_CHANGE_PENDING', 'Another seat change is already scheduled.', 409);
            }
            $row = DB::table('billing_component_quantity_changes')->where('company_id', $companyId)
                ->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($row !== null && ($row->component_subscription_id !== $component->id
                || (int) $row->target_quantity !== $targetQuantity)) {
                throw new PlatformException('BILLING_IDEMPOTENCY_CONFLICT', 'The idempotency key belongs to another seat change.', 409);
            }
            if ($row === null && DB::table('billing_component_quantity_changes')->where('company_id', $companyId)
                ->where('component_subscription_id', $component->id)
                ->whereIn('status', ['CREATING', 'UNCERTAIN', 'SCHEDULED'])->exists()) {
                throw new PlatformException('BILLING_SEAT_CHANGE_PENDING', 'Resolve the existing seat change first.', 409);
            }
            if ($row !== null && $row->status === 'CREATING'
                && CarbonImmutable::parse($row->claimed_at)->isAfter(now()->subMinutes(2))) {
                throw new PlatformException('BILLING_SEAT_CHANGE_IN_PROGRESS', 'Seat change is still in progress.', 409);
            }
            $generation = (int) ($row->claim_generation ?? 0) + 1;
            if ($generation <= 0 || $generation >= PHP_INT_MAX) {
                throw new PlatformException('BILLING_CLAIM_EXHAUSTED', 'Seat-change claim generation is exhausted.', 409);
            }
            $id = $row->id ?? (string) Str::uuid();
            if ($row === null) {
                DB::table('billing_component_quantity_changes')->insert([
                    'id' => $id, 'company_id' => $companyId, 'component_subscription_id' => $component->id,
                    'idempotency_key' => $key, 'previous_quantity' => $component->quantity,
                    'target_quantity' => $targetQuantity, 'status' => 'CREATING',
                    'claim_generation' => $generation, 'claimed_at' => now(),
                    'requested_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('billing_component_quantity_changes')->where('id', $id)->update([
                    'status' => 'CREATING', 'claim_generation' => $generation,
                    'claimed_at' => now(), 'updated_at' => now(),
                ]);
            }

            return [$id, $generation];
        });
        try {
            $updated = $this->gateway->scheduleComponentQuantity($component->provider_subscription_id,
                $item['id'], $targetQuantity, 'zavsync-seat-reduction:'.$id);
            $periodEnd = $updated['current_period_end'] ?? ($updated['items']['data'][0]['current_period_end'] ?? null);
            if (($updated['id'] ?? null) !== $component->provider_subscription_id
                || ($updated['customer'] ?? null) !== $customerId || ($updated['status'] ?? null) !== 'active'
                || ($updated['items']['data'][0]['quantity'] ?? null) !== $targetQuantity
                || ($updated['items']['data'][0]['price']['id'] ?? null) !== $component->cataloguePrice->provider_price_id
                || ! is_int($periodEnd) || $periodEnd !== $component->paid_through_at->timestamp) {
                throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe did not confirm next-cycle seat reduction.', 409);
            }
        } catch (\Throwable $exception) {
            DB::table('billing_component_quantity_changes')->where('id', $id)
                ->where('claim_generation', $generation)->where('status', 'CREATING')
                ->update(['status' => 'UNCERTAIN', 'updated_at' => now()]);
            if ($exception instanceof PlatformException) {
                throw $exception;
            }
            throw new PlatformException('BILLING_PROVIDER_UNAVAILABLE', 'The seat change result is uncertain. Retry with the same key.', 503);
        }
        DB::transaction(function () use ($companyId, $component, $targetQuantity, $id, $generation, $user): void {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $row = DB::table('billing_component_quantity_changes')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || (int) $row->claim_generation !== $generation || $row->status !== 'CREATING') {
                throw new PlatformException('BILLING_SEAT_CHANGE_SUPERSEDED', 'The seat-change claim was superseded.', 409);
            }
            $current = BillingComponentSubscription::query()->where('company_id', $companyId)
                ->lockForUpdate()->findOrFail($component->id);
            if ($current->quantity !== $component->quantity || $current->scheduled_quantity !== null) {
                throw new PlatformException('BILLING_SEAT_CHANGE_PENDING', 'Seat quantity changed before provider confirmation.', 409);
            }
            $current->update(['scheduled_quantity' => $targetQuantity]);
            DB::table('billing_component_quantity_changes')->where('id', $id)->update([
                'status' => 'SCHEDULED', 'effective_at' => $current->paid_through_at,
                'updated_at' => now(),
            ]);
            $this->audit->recordOperation($user, $companyId, 'billing_seat_reduction_scheduled', 'platform', $current,
                ['quantity' => $component->quantity], ['target_quantity' => $targetQuantity]);
        });

        return $this->seatReductionResponse($component, $targetQuantity);
    }

    /** @return array{component_id: string, status: string, current_quantity: int, scheduled_quantity: int, effective_at: string} */
    private function seatReductionResponse(BillingComponentSubscription $component, int $targetQuantity, string $status = 'SCHEDULED'): array
    {
        return ['component_id' => $component->id, 'status' => $status,
            'current_quantity' => $component->quantity, 'scheduled_quantity' => $targetQuantity,
            'effective_at' => $component->paid_through_at->toISOString()];
    }

    /** @param array<string, mixed> $event
     * @param  array<string, mixed>|null  $invoice
     */
    public function applyProviderEvent(BillingComponentSubscription $component, array $event, ?array $invoice): string
    {
        $companyId = $component->company_id;
        $component = BillingComponentSubscription::query()->where('company_id', $companyId)
            ->findOrFail($component->id);
        $customerId = DB::table('billing_customers')->where('company_id', $companyId)->where('provider', 'stripe')
            ->value('provider_customer_id');
        $provider = $this->gateway->retrieveSubscription($component->provider_subscription_id);
        $items = $provider['items']['data'] ?? null;
        $price = $component->catalogue_price_id === null ? null
            : DB::table('billing_catalogue_prices')->where('id', $component->catalogue_price_id)->value('provider_price_id');
        if (! is_string($customerId) || ($provider['id'] ?? null) !== $component->provider_subscription_id
            || ($provider['customer'] ?? null) !== $customerId || ! is_array($items) || count($items) !== 1
            || ($items[0]['price']['id'] ?? null) !== $price
            || strtoupper((string) ($items[0]['price']['currency'] ?? '')) !== $component->currency
            || ($items[0]['quantity'] ?? null) !== ($component->scheduled_quantity ?? $component->quantity)
            || ($invoice !== null && (($invoice['customer'] ?? null) !== $customerId
                || strtoupper((string) ($invoice['currency'] ?? '')) !== $component->currency))) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'The component event does not match the company purchase.', 409);
        }
        $periodEnd = $provider['current_period_end'] ?? ($items[0]['current_period_end'] ?? null);
        if (! is_int($periodEnd)) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe did not provide a component billing period.', 409);
        }
        $eventType = $event['type'];
        if ($eventType === 'invoice.paid') {
            $invoicePeriodEnd = $invoice['lines']['data'][0]['period']['end'] ?? null;
            if (($provider['status'] ?? null) !== 'active' || ($invoice['status'] ?? null) !== 'paid' || ! is_int($invoicePeriodEnd)
                || $invoicePeriodEnd !== $periodEnd || ! is_int($invoice['subtotal'] ?? null)
                || ! is_int($invoice['total'] ?? null) || ! is_int($invoice['amount_paid'] ?? null)
                || $invoice['subtotal'] < 0 || $invoice['total'] < 0 || $invoice['amount_paid'] < 0) {
                throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'The component renewal payment is not confirmed.', 409);
            }
            $expectedQuantity = $component->scheduled_quantity ?? $component->quantity;
            $unitAmount = $component->cataloguePrice->unit_amount_minor;
            if (! is_int($unitAmount) || $unitAmount > intdiv(PHP_INT_MAX, $expectedQuantity)
                || $invoice['subtotal'] !== $unitAmount * $expectedQuantity
                || $invoice['amount_paid'] !== $invoice['total']
                || ($invoice['lines']['data'][0]['quantity'] ?? null) !== $expectedQuantity
                || ($invoice['lines']['data'][0]['price']['id'] ?? $invoice['lines']['data'][0]['pricing']['price_details']['price'] ?? null)
                    !== $component->cataloguePrice->provider_price_id) {
                throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'The component renewal quantity and full payment are not verified.', 409);
            }
        }
        DB::transaction(function () use ($companyId, $component, $provider, $periodEnd, $eventType, $event, $invoice): void {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = BillingComponentSubscription::query()->where('company_id', $companyId)
                ->lockForUpdate()->findOrFail($component->id);
            if ($eventType === 'invoice.paid' && $periodEnd > $current->paid_through_at->timestamp) {
                $previousEnd = $current->paid_through_at;
                $nextQuantity = $current->scheduled_quantity ?? $current->quantity;
                $current->update(['paid_through_at' => CarbonImmutable::createFromTimestampUTC($periodEnd),
                    'quantity' => $nextQuantity, 'scheduled_quantity' => null, 'status' => 'ACTIVE']);
                DB::table('billing_subscription_items')->where('company_id', $companyId)
                    ->where('component_subscription_id', $current->id)->where('ends_at', '<', CarbonImmutable::createFromTimestampUTC($periodEnd))
                    ->update(['ends_at' => CarbonImmutable::createFromTimestampUTC($periodEnd),
                        'quantity' => $nextQuantity,
                        'total_minor' => $current->cataloguePrice->unit_amount_minor * $nextQuantity,
                        'updated_at' => now()]);
                DB::table('billing_component_quantity_changes')->where('company_id', $companyId)
                    ->where('component_subscription_id', $current->id)->where('status', 'SCHEDULED')
                    ->where('effective_at', '<=', $previousEnd)
                    ->update(['status' => 'APPLIED', 'completed_at' => now(), 'updated_at' => now()]);
                $this->audit->recordSystemOperation($companyId, 'billing_component_renewed', 'platform', $current,
                    ['provider_event_id' => $event['id'], 'paid_through_at' => $current->paid_through_at->toISOString()]);
            }
            if (($provider['cancel_at_period_end'] ?? false) === true) {
                $current->update(['cancel_at_period_end_at' => $current->paid_through_at]);
            }
            if (($provider['status'] ?? null) === 'canceled' && $current->paid_through_at->isPast()) {
                $current->update(['status' => 'EXPIRED']);
            }
            if ($invoice !== null) {
                $this->storeComponentInvoice($current, $invoice);
            }
        });
        $this->entitlements->forget($companyId);

        return $companyId;
    }

    /** @param array<string, mixed> $invoice */
    private function storeComponentInvoice(BillingComponentSubscription $component, array $invoice): void
    {
        $id = $invoice['id'] ?? null;
        $status = $invoice['status'] ?? null;
        if (! is_string($id) || ! str_starts_with($id, 'in_') || ! is_string($status)
            || strlen($status) > 30 || ! is_int($invoice['subtotal'] ?? null)
            || ! is_int($invoice['total'] ?? null) || $invoice['subtotal'] < 0 || $invoice['total'] < 0) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'The component invoice fields are invalid.', 409);
        }
        $existing = DB::table('billing_documents')->where('provider_document_id', $id)->first();
        if ($existing !== null && ($existing->company_id !== $component->company_id
            || $existing->component_subscription_id !== $component->id)) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'The Stripe document belongs to another purchase.', 409);
        }
        if ($existing === null) {
            DB::table('billing_documents')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $component->company_id,
                'subscription_id' => $component->subscription_id,
                'component_subscription_id' => $component->id, 'provider_document_id' => $id,
                'document_type' => 'INVOICE', 'provider_status' => $status,
                'currency' => $component->currency, 'subtotal_minor' => $invoice['subtotal'],
                'total_minor' => $invoice['total'], 'paid_minor' => $invoice['amount_paid'] ?? null,
                'line_snapshot' => json_encode([['price_id' => $component->cataloguePrice?->provider_price_id,
                    'quantity' => $component->quantity]], JSON_THROW_ON_ERROR),
                'issued_at' => isset($invoice['created']) && is_int($invoice['created'])
                    ? CarbonImmutable::createFromTimestampUTC($invoice['created']) : null,
                'paid_at' => isset($invoice['status_transitions']['paid_at']) && is_int($invoice['status_transitions']['paid_at'])
                    ? CarbonImmutable::createFromTimestampUTC($invoice['status_transitions']['paid_at']) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } elseif ($existing->provider_status !== 'paid' && $status === 'paid') {
            DB::table('billing_documents')->where('id', $existing->id)->update([
                'provider_status' => 'paid', 'paid_minor' => $invoice['amount_paid'] ?? null,
                'paid_at' => isset($invoice['status_transitions']['paid_at']) && is_int($invoice['status_transitions']['paid_at'])
                    ? CarbonImmutable::createFromTimestampUTC($invoice['status_transitions']['paid_at']) : now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return array{attempt_id: string, status: string, checkout_url: ?string} */
    private function replay(BillingCheckoutAttempt $attempt): array
    {
        $url = null;
        if ($attempt->status === 'PAYMENT_PENDING' && $attempt->provider_session_id !== null) {
            $session = $this->gateway->retrieveCheckout($attempt->provider_session_id);
            if (($session['id'] ?? null) !== $attempt->provider_session_id
                || ($session['client_reference_id'] ?? null) !== $attempt->id) {
                throw new PlatformException('BILLING_PROVIDER_RESPONSE_INVALID', 'Stripe checkout ownership could not be confirmed.', 503);
            }
            $url = $this->safeCheckoutUrl($session['url'] ?? null);
        }

        return ['attempt_id' => $attempt->id, 'status' => $attempt->status, 'checkout_url' => $url];
    }

    private function safeCheckoutUrl(mixed $url): ?string
    {
        return is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_HOST) === 'checkout.stripe.com' ? $url : null;
    }

    private function paidBaseSubscription(string $companyId): Subscription
    {
        $company = Company::query()->findOrFail($companyId);
        if (! $company->is_active) {
            throw new PlatformException('COMPANY_INACTIVE', 'This company is inactive.', 403);
        }
        if (! $company->billing_policy_applies) {
            throw new PlatformException('BILLING_MANAGED_COMPANY_REQUIRED', 'This company does not use the managed billing lifecycle.', 409);
        }
        $subscription = Subscription::query()->with('plan')->where('company_id', $companyId)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($subscription === null || $subscription->status !== 'ACTIVE' || $subscription->provider !== 'stripe'
            || $subscription->provider_reference === null || ! $subscription->renews_at?->isFuture()
            || $subscription->cancel_at_period_end_at !== null) {
            throw new PlatformException('BILLING_ACTIVE_PLAN_REQUIRED', 'An active paid plan is required for this purchase.', 409);
        }

        return $subscription;
    }
}
