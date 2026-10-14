<?php

namespace App\Services\Platform;

use App\Contracts\BillingGateway;
use App\Exceptions\PlatformException;
use App\Models\BillingCataloguePrice;
use App\Models\BillingCheckoutAttempt;
use App\Models\BillingComponentSubscription;
use App\Models\BillingPlanChange;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingPlanChangeService
{
    /** @var array<string, int> */
    private const PLAN_RANK = ['starter' => 1, 'professional' => 2, 'enterprise' => 3];

    public function __construct(
        private readonly BillingCatalogueService $catalogue,
        private readonly BillingGateway $gateway,
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
        private readonly BillingComponentService $components,
    ) {}

    /** @return array{change_id: string, operation: string, status: string, effective_at: ?string} */
    public function begin(string $companyId, User $user, string $targetPlan, string $interval, string $key): array
    {
        if (config('services.stripe.enabled') !== true || blank(config('services.stripe.secret'))
            || blank(config('services.stripe.webhook_secret'))) {
            throw new PlatformException('BILLING_PROVIDER_NOT_CONFIGURED', 'Stripe billing is not configured.', 503);
        }
        if (! isset(self::PLAN_RANK[$targetPlan]) || ! in_array($interval, ['monthly', 'annual'], true)) {
            throw new PlatformException('BILLING_SELECTION_INVALID', 'Select a supported target plan and interval.', 422);
        }
        $prior = BillingPlanChange::query()->where('company_id', $companyId)->where('idempotency_key', $key)->first();
        if ($prior !== null && ($prior->target_plan_code !== $targetPlan
            || $prior->target_billing_interval !== $interval)) {
            throw new PlatformException('BILLING_IDEMPOTENCY_CONFLICT', 'The idempotency key belongs to another plan change.', 409);
        }
        if ($prior !== null && in_array($prior->status, ['PAYMENT_PENDING', 'SCHEDULED', 'APPLIED',
            'APPLIED_CANCELLATION_PENDING'], true)) {
            return $this->finishIncludedAddonCancellation($prior);
        }
        $subscription = $this->paidSubscription($companyId);
        $sourcePlan = $subscription->plan->code;
        if (! isset(self::PLAN_RANK[$sourcePlan]) || $targetPlan === $sourcePlan) {
            throw new PlatformException('BILLING_PLAN_CHANGE_INVALID', 'Choose a different supported plan.', 422);
        }
        $operation = self::PLAN_RANK[$targetPlan] > self::PLAN_RANK[$sourcePlan] ? 'UPGRADE' : 'DOWNGRADE';
        $sourceItem = DB::table('billing_subscription_items')->where('company_id', $companyId)
            ->where('subscription_id', $subscription->id)->where('kind', 'plan')->whereNull('ends_at')->first();
        $sourcePrice = $sourceItem === null ? null : BillingCataloguePrice::query()->find($sourceItem->catalogue_price_id);
        if ($sourcePrice === null || ! is_string($sourcePrice->provider_price_id)) {
            throw new PlatformException('BILLING_PLAN_HISTORY_MISSING', 'The active plan price cannot be verified.', 409);
        }
        $currency = $this->catalogue->billingJurisdiction($companyId)['currency'];
        if ($currency !== $subscription->billing_currency) {
            throw new PlatformException('BILLING_CURRENCY_MISMATCH', 'The company billing currency differs from the paid plan.', 409);
        }
        $targetPrice = $this->catalogue->price('plan', $targetPlan, $currency, $interval);
        if (! is_string($targetPrice->provider_price_id) || ! preg_match('/^price_[A-Za-z0-9]+$/', $targetPrice->provider_price_id)) {
            throw new PlatformException('BILLING_PRICE_NOT_CONFIGURED', 'The target Stripe plan price is not configured.', 503);
        }
        $hash = hash('sha256', json_encode([$companyId, $subscription->id, $operation, $targetPrice->id], JSON_THROW_ON_ERROR));
        $existing = BillingPlanChange::query()->where('company_id', $companyId)->where('idempotency_key', $key)->first();
        if ($existing !== null && ! hash_equals($existing->payload_hash, $hash)) {
            throw new PlatformException('BILLING_IDEMPOTENCY_CONFLICT', 'The idempotency key belongs to another plan change.', 409);
        }
        if ($existing !== null && in_array($existing->status, ['PAYMENT_PENDING', 'SCHEDULED'], true)) {
            return $this->response($existing);
        }
        if ($existing !== null && in_array($existing->status, ['APPLIED', 'APPLIED_CANCELLATION_PENDING'], true)) {
            return $this->finishIncludedAddonCancellation($existing);
        }
        if ($existing !== null && $existing->status === 'OUTCOME_UNCERTAIN') {
            $recovered = $this->reconcile($companyId, $existing->id);
            if ($recovered['status'] === 'APPLIED') {
                return $recovered;
            }
        }
        $providerPrice = $this->gateway->retrievePrice($targetPrice->provider_price_id);
        if (($providerPrice['id'] ?? null) !== $targetPrice->provider_price_id
            || ($providerPrice['active'] ?? null) !== true
            || strtoupper((string) ($providerPrice['currency'] ?? '')) !== $currency
            || ($providerPrice['unit_amount'] ?? null) !== $targetPrice->unit_amount_minor
            || ($providerPrice['recurring']['interval'] ?? null) !== ($interval === 'annual' ? 'year' : 'month')) {
            throw new PlatformException('BILLING_PRICE_MISMATCH', 'The Stripe target price does not match the approved catalogue.', 409);
        }
        $provider = $this->gateway->retrieveSubscription($subscription->provider_reference);
        $item = $provider['items']['data'][0] ?? null;
        $customerId = DB::table('billing_customers')->where('company_id', $companyId)->where('provider', 'stripe')
            ->value('provider_customer_id');
        $periodStart = $provider['current_period_start'] ?? ($item['current_period_start'] ?? null);
        $periodEnd = $provider['current_period_end'] ?? ($item['current_period_end'] ?? null);
        if (($provider['id'] ?? null) !== $subscription->provider_reference
            || ($provider['customer'] ?? null) !== $customerId || ($provider['status'] ?? null) !== 'active'
            || ! is_array($item) || count($provider['items']['data'] ?? []) !== 1
            || ! is_string($item['id'] ?? null) || ! str_starts_with($item['id'], 'si_')
            || ($item['price']['id'] ?? null) !== $sourcePrice->provider_price_id
            || strtoupper((string) ($item['price']['currency'] ?? '')) !== $currency
            || ! is_int($periodStart) || ! is_int($periodEnd) || $periodEnd <= now()->timestamp
            || abs($periodEnd - $subscription->renews_at->timestamp) > 60) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'The current Stripe plan and paid period do not match this company.', 409);
        }
        if ($operation === 'DOWNGRADE' && ($provider['schedule'] ?? null) !== null && $existing === null) {
            throw new PlatformException('BILLING_SCHEDULE_ALREADY_EXISTS', 'An existing provider schedule must be reviewed before changing plans.', 409);
        }

        [$change, $generation, $claimed] = DB::transaction(function () use ($companyId, $user, $subscription, $sourcePrice, $targetPrice,
            $sourcePlan, $targetPlan, $interval, $currency, $operation, $key, $hash): array {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            if ($this->paidSubscription($companyId)->id !== $subscription->id) {
                throw new PlatformException('BILLING_SUBSCRIPTION_CHANGED', 'The active plan changed before this request.', 409);
            }
            if (BillingCheckoutAttempt::query()->where('company_id', $companyId)
                ->where('operation', 'COMPONENT_CHECKOUT')
                ->whereIn('status', ['CREATING', 'OUTCOME_UNCERTAIN', 'PAYMENT_PENDING'])->exists()) {
                throw new PlatformException('BILLING_COMPONENT_ALREADY_PENDING', 'Resolve component checkout before changing the plan.', 409);
            }
            $change = BillingPlanChange::query()->where('company_id', $companyId)->where('idempotency_key', $key)
                ->lockForUpdate()->first();
            if ($change !== null && ! hash_equals($change->payload_hash, $hash)) {
                throw new PlatformException('BILLING_IDEMPOTENCY_CONFLICT', 'The idempotency key belongs to another plan change.', 409);
            }
            if ($change !== null && in_array($change->status, ['PAYMENT_PENDING', 'SCHEDULED'], true)) {
                return [$change, null, false];
            }
            if ($change === null && BillingPlanChange::query()->where('company_id', $companyId)
                ->where('subscription_id', $subscription->id)
                ->whereIn('status', ['CREATING', 'OUTCOME_UNCERTAIN', 'PAYMENT_PENDING', 'SCHEDULED'])->exists()) {
                throw new PlatformException('BILLING_PLAN_CHANGE_PENDING', 'Resolve the existing plan change first.', 409);
            }
            if ($change !== null && $change->status === 'CREATING' && $change->claimed_at?->isAfter(now()->subMinutes(2))) {
                throw new PlatformException('BILLING_PLAN_CHANGE_IN_PROGRESS', 'Plan change is still in progress.', 409);
            }
            $generation = ($change?->claim_generation ?? 0) + 1;
            if ($generation <= 0 || $generation >= PHP_INT_MAX) {
                throw new PlatformException('BILLING_CLAIM_EXHAUSTED', 'Plan-change claim generation is exhausted.', 409);
            }
            $change ??= BillingPlanChange::query()->create([
                'company_id' => $companyId, 'subscription_id' => $subscription->id,
                'source_catalogue_price_id' => $sourcePrice->id, 'target_catalogue_price_id' => $targetPrice->id,
                'idempotency_key' => $key, 'payload_hash' => $hash, 'operation' => $operation,
                'status' => 'CREATING', 'source_plan_code' => $sourcePlan, 'target_plan_code' => $targetPlan,
                'target_billing_interval' => $interval, 'currency' => $currency,
                'target_amount_minor' => $targetPrice->unit_amount_minor,
                'previous_paid_through_at' => $subscription->renews_at, 'requested_by' => $user->id,
            ]);
            $change->update(['claim_generation' => $generation, 'claimed_at' => now(), 'status' => 'CREATING']);

            return [$change, $generation, true];
        });
        if (! $claimed) {
            return $this->response($change);
        }
        try {
            if ($operation === 'UPGRADE') {
                $result = $this->gateway->upgradePlan($subscription->provider_reference, $item['id'],
                    $targetPrice->provider_price_id, 'zavsync-plan-upgrade:'.$change->id);
                if (($result['id'] ?? null) !== $subscription->provider_reference
                    || ($result['customer'] ?? null) !== $customerId
                    || ! is_string($result['latest_invoice'] ?? null)
                    || ! str_starts_with($result['latest_invoice'], 'in_')) {
                    throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe did not return an upgrade invoice for this company.', 409);
                }
                $fields = ['status' => 'PAYMENT_PENDING', 'provider_invoice_id' => $result['latest_invoice']];
            } else {
                $created = $this->gateway->createScheduleFromSubscription($subscription->provider_reference,
                    'zavsync-plan-schedule:'.$change->id);
                if (! is_string($created['id'] ?? null) || ! str_starts_with($created['id'], 'sub_sched_')
                    || ($created['subscription'] ?? null) !== $subscription->provider_reference) {
                    throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe did not confirm a schedule for this subscription.', 409);
                }
                $result = $this->gateway->schedulePlanChange($created['id'], $sourcePrice->provider_price_id,
                    $targetPrice->provider_price_id, $periodStart, $periodEnd, $interval,
                    'zavsync-plan-schedule-phases:'.$change->id);
                $phase = $result['phases'][1] ?? null;
                if (($result['id'] ?? null) !== $created['id']
                    || ($result['subscription'] ?? null) !== $subscription->provider_reference
                    || ($phase['start_date'] ?? null) !== $periodEnd
                    || ($phase['items'][0]['price'] ?? null) !== $targetPrice->provider_price_id) {
                    throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe did not confirm the period-end target phase.', 409);
                }
                $fields = ['status' => 'SCHEDULED', 'provider_schedule_id' => $created['id'],
                    'effective_at' => CarbonImmutable::createFromTimestampUTC($periodEnd)];
            }
        } catch (\Throwable $exception) {
            DB::transaction(function () use ($change, $generation): void {
                $current = BillingPlanChange::query()->whereKey($change->id)->lockForUpdate()->firstOrFail();
                if ($current->claim_generation === $generation && $current->status === 'CREATING') {
                    $current->update(['status' => 'OUTCOME_UNCERTAIN']);
                }
            });
            if ($exception instanceof PlatformException) {
                throw $exception;
            }
            throw new PlatformException('BILLING_PROVIDER_UNAVAILABLE', 'The provider result is uncertain. Retry with the same idempotency key.', 503);
        }
        $current = DB::transaction(function () use ($change, $generation, $fields): BillingPlanChange {
            Company::query()->whereKey($change->company_id)->lockForUpdate()->firstOrFail();
            $current = BillingPlanChange::query()->whereKey($change->id)->lockForUpdate()->firstOrFail();
            if ($current->claim_generation === $generation && $current->status === 'CREATING') {
                $current->update($fields);
            }

            return $current;
        });
        if ($current->claim_generation !== $generation) {
            throw new PlatformException('BILLING_PLAN_CHANGE_SUPERSEDED', 'This plan-change claim was superseded.', 409);
        }
        $this->audit->recordOperation($user, $companyId, 'billing_plan_change_requested', 'platform', $current,
            null, ['operation' => $operation, 'target_plan' => $targetPlan, 'status' => $current->status]);

        return $this->response($current);
    }

    /** @return array{change_id: string, operation: string, status: string, effective_at: ?string} */
    public function reconcile(string $companyId, string $changeId): array
    {
        $change = BillingPlanChange::query()->where('company_id', $companyId)->findOrFail($changeId);
        if (in_array($change->status, ['APPLIED', 'APPLIED_CANCELLATION_PENDING'], true)) {
            return $this->finishIncludedAddonCancellation($change);
        }
        $subscription = Subscription::query()->where('company_id', $companyId)->findOrFail($change->subscription_id);
        $provider = $this->gateway->retrieveSubscription($subscription->provider_reference);
        $targetPrice = BillingCataloguePrice::query()->findOrFail($change->target_catalogue_price_id);
        $customerId = DB::table('billing_customers')->where('company_id', $companyId)->where('provider', 'stripe')
            ->value('provider_customer_id');
        $item = $provider['items']['data'][0] ?? null;
        if (($provider['id'] ?? null) !== $subscription->provider_reference
            || ($provider['customer'] ?? null) !== $customerId || ! is_array($item)
            || count($provider['items']['data'] ?? []) !== 1
            || strtoupper((string) ($item['price']['currency'] ?? '')) !== $change->currency) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'The Stripe plan change does not belong to this company.', 409);
        }
        if (($item['price']['id'] ?? null) !== $targetPrice->provider_price_id
            || ($provider['pending_update'] ?? null) !== null) {
            return $this->response($change);
        }
        $periodStart = $provider['current_period_start'] ?? ($item['current_period_start'] ?? null);
        $periodEnd = $provider['current_period_end'] ?? ($item['current_period_end'] ?? null);
        $invoiceId = $change->operation === 'UPGRADE' && $change->provider_invoice_id !== null
            ? $change->provider_invoice_id : ($provider['latest_invoice'] ?? null);
        if (($provider['status'] ?? null) !== 'active' || ! is_int($periodStart) || ! is_int($periodEnd)
            || $periodEnd <= now()->timestamp || ! is_string($invoiceId) || ! str_starts_with($invoiceId, 'in_')) {
            return $this->response($change);
        }
        if ($change->operation === 'DOWNGRADE' && ($change->effective_at === null
            || $periodStart < $change->effective_at->timestamp)) {
            return $this->response($change);
        }
        if ($change->operation === 'UPGRADE' && $periodStart < $change->created_at->timestamp - 60) {
            return $this->response($change);
        }
        $invoice = $this->gateway->retrieveInvoice($invoiceId);
        $invoiceSubscription = $invoice['subscription'] ?? ($invoice['parent']['subscription_details']['subscription'] ?? null);
        if (($invoice['id'] ?? null) !== $invoiceId || ($invoice['customer'] ?? null) !== $customerId
            || $invoiceSubscription !== $subscription->provider_reference
            || strtoupper((string) ($invoice['currency'] ?? '')) !== $change->currency) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'The plan-change invoice does not belong to this company.', 409);
        }
        if (($invoice['status'] ?? null) !== 'paid') {
            return $this->response($change);
        }
        if (($invoice['subtotal'] ?? null) !== $change->target_amount_minor
            || ! is_int($invoice['total'] ?? null) || $invoice['total'] < $invoice['subtotal']
            || ($invoice['amount_paid'] ?? null) !== $invoice['total']
            || ($invoice['lines']['data'][0]['price']['id'] ?? $invoice['lines']['data'][0]['pricing']['price_details']['price'] ?? null)
                !== $targetPrice->provider_price_id
            || ($invoice['lines']['data'][0]['period']['end'] ?? null) !== $periodEnd) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'The full target-plan payment and new period were not verified.', 409);
        }
        $applied = DB::transaction(function () use ($companyId, $changeId, $periodStart, $periodEnd, $invoice,
            $invoiceId, $targetPrice): BillingPlanChange {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = BillingPlanChange::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($changeId);
            if (in_array($current->status, ['APPLIED', 'APPLIED_CANCELLATION_PENDING'], true)) {
                return $current;
            }
            if (! in_array($current->status, ['PAYMENT_PENDING', 'SCHEDULED', 'OUTCOME_UNCERTAIN', 'CREATING'], true)) {
                throw new PlatformException('BILLING_PLAN_CHANGE_STATE_CHANGED', 'The plan change cannot be applied from this state.', 409);
            }
            $base = Subscription::query()->where('company_id', $companyId)->lockForUpdate()
                ->findOrFail($current->subscription_id);
            if ($base->plan?->code !== $current->source_plan_code || $base->billing_currency !== $current->currency) {
                throw new PlatformException('BILLING_SUBSCRIPTION_CHANGED', 'The base plan changed before provider confirmation.', 409);
            }
            $targetPlan = Plan::query()->where('code', $current->target_plan_code)->first();
            if ($targetPlan === null) {
                throw new PlatformException('BILLING_PLAN_NOT_CONFIGURED', 'The target plan is not configured.', 503);
            }
            DB::table('billing_subscription_items')->where('company_id', $companyId)
                ->where('subscription_id', $base->id)->where('kind', 'plan')->whereNull('ends_at')
                ->update(['ends_at' => CarbonImmutable::createFromTimestampUTC($periodStart), 'updated_at' => now()]);
            DB::table('billing_subscription_items')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId, 'subscription_id' => $base->id,
                'catalogue_price_id' => $targetPrice->id, 'kind' => 'plan', 'code' => $current->target_plan_code,
                'currency' => $current->currency, 'billing_interval' => $current->target_billing_interval,
                'unit_amount_minor' => $current->target_amount_minor, 'quantity' => 1,
                'total_minor' => $current->target_amount_minor,
                'effective_at' => CarbonImmutable::createFromTimestampUTC($periodStart),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $base->update(['plan_id' => $targetPlan->id, 'billing_interval' => $current->target_billing_interval,
                'starts_at' => CarbonImmutable::createFromTimestampUTC($periodStart),
                'renews_at' => CarbonImmutable::createFromTimestampUTC($periodEnd),
                'status' => 'ACTIVE', 'grace_ends_at' => null]);
            $existingDocument = DB::table('billing_documents')->where('provider_document_id', $invoiceId)->first();
            if ($existingDocument !== null && $existingDocument->company_id !== $companyId) {
                throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'The provider invoice belongs to another company.', 409);
            }
            if ($existingDocument === null) {
                DB::table('billing_documents')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $companyId, 'subscription_id' => $base->id,
                    'provider_document_id' => $invoiceId, 'document_type' => 'INVOICE',
                    'provider_status' => 'paid', 'currency' => $current->currency,
                    'subtotal_minor' => $invoice['subtotal'], 'total_minor' => $invoice['total'],
                    'paid_minor' => $invoice['amount_paid'],
                    'line_snapshot' => json_encode([['price_id' => $targetPrice->provider_price_id,
                        'amount_minor' => $invoice['subtotal'], 'quantity' => 1]], JSON_THROW_ON_ERROR),
                    'issued_at' => isset($invoice['created']) && is_int($invoice['created'])
                        ? CarbonImmutable::createFromTimestampUTC($invoice['created']) : null,
                    'paid_at' => isset($invoice['status_transitions']['paid_at']) && is_int($invoice['status_transitions']['paid_at'])
                        ? CarbonImmutable::createFromTimestampUTC($invoice['status_transitions']['paid_at']) : now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $needsAddonCancellation = $current->operation === 'UPGRADE'
                && BillingComponentSubscription::query()->where('company_id', $companyId)
                    ->where('subscription_id', $base->id)->where('kind', 'addon')
                    ->whereIn('code', $this->catalogue->includedAddons($current->target_plan_code))
                    ->whereNull('cancel_at_period_end_at')->where('paid_through_at', '>', now())->exists();
            $current->update(['status' => $needsAddonCancellation ? 'APPLIED_CANCELLATION_PENDING' : 'APPLIED',
                'effective_at' => CarbonImmutable::createFromTimestampUTC($periodStart),
                'provider_invoice_id' => $invoiceId, 'completed_at' => now()]);
            $this->audit->recordSystemOperation($companyId, 'billing_plan_change_confirmed', 'platform', $current,
                ['operation' => $current->operation, 'target_plan' => $current->target_plan_code]);

            return $current;
        });
        $this->entitlements->forget($companyId);

        return $this->finishIncludedAddonCancellation($applied);
    }

    /** @return array{change_id: string, operation: string, status: string, effective_at: ?string} */
    private function finishIncludedAddonCancellation(BillingPlanChange $change): array
    {
        if ($change->status !== 'APPLIED_CANCELLATION_PENDING') {
            return $this->response($change);
        }
        $actor = User::query()->findOrFail($change->requested_by);
        $components = BillingComponentSubscription::query()->where('company_id', $change->company_id)
            ->where('subscription_id', $change->subscription_id)->where('kind', 'addon')
            ->whereIn('code', $this->catalogue->includedAddons($change->target_plan_code))
            ->whereNull('cancel_at_period_end_at')->where('paid_through_at', '>', now())->get();
        foreach ($components as $component) {
            $this->components->cancel($change->company_id, $actor, $component->id);
        }
        $current = DB::transaction(function () use ($change): BillingPlanChange {
            Company::query()->whereKey($change->company_id)->lockForUpdate()->firstOrFail();
            $current = BillingPlanChange::query()->where('company_id', $change->company_id)
                ->lockForUpdate()->findOrFail($change->id);
            $remaining = BillingComponentSubscription::query()->where('company_id', $change->company_id)
                ->where('subscription_id', $change->subscription_id)->where('kind', 'addon')
                ->whereIn('code', $this->catalogue->includedAddons($change->target_plan_code))
                ->whereNull('cancel_at_period_end_at')->where('paid_through_at', '>', now())->exists();
            if (! $remaining && $current->status === 'APPLIED_CANCELLATION_PENDING') {
                $current->update(['status' => 'APPLIED']);
            }

            return $current;
        });

        return $this->response($current);
    }

    /** @param array<string, mixed> $provider */
    public function reconcilePendingForSubscription(Subscription $subscription, array $provider): void
    {
        $change = BillingPlanChange::query()->where('company_id', $subscription->company_id)
            ->where('subscription_id', $subscription->id)
            ->whereIn('status', ['PAYMENT_PENDING', 'SCHEDULED', 'OUTCOME_UNCERTAIN', 'CREATING'])
            ->orderByDesc('created_at')->first();
        if ($change === null) {
            return;
        }
        $targetPriceId = BillingCataloguePrice::query()->whereKey($change->target_catalogue_price_id)
            ->value('provider_price_id');
        if (($provider['items']['data'][0]['price']['id'] ?? null) === $targetPriceId) {
            $this->reconcile($subscription->company_id, $change->id);
        }
    }

    /** @return array{change_id: string, operation: string, status: string, effective_at: ?string} */
    private function response(BillingPlanChange $change): array
    {
        return ['change_id' => $change->id, 'operation' => $change->operation,
            'status' => $change->status, 'effective_at' => $change->effective_at?->toISOString()];
    }

    private function paidSubscription(string $companyId): Subscription
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
            throw new PlatformException('BILLING_ACTIVE_PLAN_REQUIRED', 'An active renewing paid plan is required for a plan change.', 409);
        }

        return $subscription;
    }
}
