<?php

namespace App\Services\Platform;

use App\Contracts\BillingGateway;
use App\Exceptions\PlatformException;
use App\Models\BillingCheckoutAttempt;
use App\Models\BillingComponentSubscription;
use App\Models\Company;
use App\Models\Subscription;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingSubscriptionLifecycleService
{
    public function __construct(
        private readonly BillingGateway $gateway,
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
        private readonly BillingNoticeService $notices,
        private readonly BillingComponentService $components,
        private readonly BillingPlanChangeService $planChanges,
    ) {}

    /** @param array<string, mixed> $event */
    public function apply(array $event): string
    {
        $type = $event['type'];
        $object = $event['data']['object'];
        $invoice = null;
        if (str_starts_with($type, 'invoice.')) {
            if (! is_string($object['id'] ?? null) || ! str_starts_with($object['id'], 'in_')) {
                throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe invoice identifier is invalid.', 409);
            }
            $invoice = $this->gateway->retrieveInvoice($object['id']);
            $subscriptionId = $invoice['subscription'] ?? ($invoice['parent']['subscription_details']['subscription'] ?? null);
            $customerId = $invoice['customer'] ?? null;
        } else {
            $subscriptionId = $object['id'] ?? null;
            $customerId = $object['customer'] ?? null;
        }
        if (! is_string($subscriptionId) || ! str_starts_with($subscriptionId, 'sub_')
            || ! is_string($customerId) || ! str_starts_with($customerId, 'cus_')) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe subscription ownership is not established.', 409);
        }
        $subscription = Subscription::query()->where('provider', 'stripe')->where('provider_reference', $subscriptionId)->first();
        if ($subscription === null) {
            $component = BillingComponentSubscription::query()->where('provider_subscription_id', $subscriptionId)->first();
            if ($component === null) {
                throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe subscription is not linked to a company.', 409);
            }

            return $this->components->applyProviderEvent($component, $event, $invoice);
        }
        $mappedCustomer = DB::table('billing_customers')->where('company_id', $subscription->company_id)->value('provider_customer_id');
        if ($mappedCustomer !== $customerId || ($invoice !== null && strtoupper((string) ($invoice['currency'] ?? '')) !== $subscription->billing_currency)) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe customer or currency differs from the company subscription.', 409);
        }
        $provider = $this->gateway->retrieveSubscription($subscriptionId);
        $this->planChanges->reconcilePendingForSubscription($subscription, $provider);
        $subscription->refresh();
        $this->assertProviderSubscription($subscription, $provider, $customerId);
        $periodEnd = $provider['current_period_end'] ?? ($provider['items']['data'][0]['current_period_end'] ?? null);
        if (! is_int($periodEnd)) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe did not provide a billing period.', 409);
        }
        $companyId = $subscription->company_id;
        DB::transaction(function () use ($companyId, $subscriptionId, $event, $provider, $periodEnd, $invoice): void {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $current = Subscription::query()->where('company_id', $companyId)->where('provider_reference', $subscriptionId)
                ->lockForUpdate()->firstOrFail();
            $priorStatus = $current->status;
            $providerStatus = $provider['status'];
            $periodEndAt = CarbonImmutable::createFromTimestampUTC($periodEnd);
            if ($providerStatus === 'canceled') {
                $current->update(['status' => 'EXPIRED', 'ends_at' => $periodEndAt, 'grace_ends_at' => null]);
            } elseif (in_array($providerStatus, ['past_due', 'unpaid'], true)) {
                $graceEnd = $current->grace_ends_at ?? CarbonImmutable::createFromTimestampUTC($event['created'])->addDays(7);
                $current->update(['status' => 'PAST_DUE_GRACE', 'grace_ends_at' => $graceEnd,
                    'renews_at' => $periodEndAt]);
            } elseif ($providerStatus === 'active') {
                $current->update(['status' => 'ACTIVE', 'grace_ends_at' => null,
                    'renews_at' => $periodEndAt,
                    'cancel_at_period_end_at' => ($provider['cancel_at_period_end'] ?? false) ? $periodEndAt : null]);
            } else {
                throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe subscription state is not supported.', 409);
            }
            if ($invoice !== null) {
                $this->storeInvoice($companyId, $current, $invoice);
            }
            if ($priorStatus !== $current->status) {
                $this->audit->recordSystemOperation($companyId, 'billing_provider_state_changed', 'platform', $current,
                    ['from' => $priorStatus, 'to' => $current->status, 'provider_event_id' => $event['id']]);
                if ($current->status === 'PAST_DUE_GRACE') {
                    $this->notices->notify($current, 'INITIAL');
                }
            }
        });
        $this->entitlements->forget($companyId);

        return $companyId;
    }

    /** @param array<string, mixed> $provider */
    private function assertProviderSubscription(Subscription $subscription, array $provider, string $customerId): void
    {
        $items = $provider['items']['data'] ?? null;
        $attempt = BillingCheckoutAttempt::query()->where('company_id', $subscription->company_id)
            ->where('provider_subscription_id', $subscription->provider_reference)->first();
        if (($provider['id'] ?? null) !== $subscription->provider_reference
            || ($provider['customer'] ?? null) !== $customerId || ! is_array($items) || $attempt === null) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe subscription does not match a verified checkout.', 409);
        }
        $actual = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_array($item['price'] ?? null)
                || strtoupper((string) ($item['price']['currency'] ?? '')) !== $subscription->billing_currency) {
                throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe subscription currency does not match the company.', 409);
            }
            $actual[] = $item['price']['id'] ?? null;
        }
        $currentPlanPriceIds = DB::table('billing_subscription_items as item')
            ->join('billing_catalogue_prices as price', 'price.id', '=', 'item.catalogue_price_id')
            ->where('item.company_id', $subscription->company_id)->where('item.subscription_id', $subscription->id)
            ->where('item.kind', 'plan')->whereNull('item.ends_at')->pluck('price.provider_price_id')->all();
        $originalPriceIds = $attempt->price_snapshot['provider_price_ids'] ?? [];
        $expected = $currentPlanPriceIds === [] || count($originalPriceIds) > 1
            ? $originalPriceIds : $currentPlanPriceIds;
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe subscription prices differ from the approved quote.', 409);
        }
    }

    /** @param array<string, mixed> $invoice */
    private function storeInvoice(string $companyId, Subscription $subscription, array $invoice): void
    {
        $id = $invoice['id'] ?? null;
        $subtotal = $invoice['subtotal'] ?? null;
        $total = $invoice['total'] ?? null;
        if (! is_string($id) || ! str_starts_with($id, 'in_') || ! is_int($subtotal) || $subtotal < 0
            || ! is_int($total) || $total < 0) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe invoice amounts are invalid.', 409);
        }
        $tax = $invoice['total_details']['amount_tax'] ?? null;
        $paid = $invoice['amount_paid'] ?? null;
        $providerStatus = $invoice['status'] ?? null;
        if (($tax !== null && (! is_int($tax) || $tax < 0)) || ($paid !== null && (! is_int($paid) || $paid < 0))) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe invoice money fields are invalid.', 409);
        }
        if (! is_string($providerStatus) || strlen($providerStatus) > 30) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe invoice status is invalid.', 409);
        }
        $lines = [];
        foreach (($invoice['lines']['data'] ?? []) as $line) {
            if (count($lines) >= 100 || ! is_array($line)) {
                break;
            }
            $lines[] = ['price_id' => $line['pricing']['price_details']['price'] ?? ($line['price']['id'] ?? null),
                'amount_minor' => $line['amount'] ?? null, 'quantity' => $line['quantity'] ?? null];
        }
        $existing = DB::table('billing_documents')->where('provider_document_id', $id)->first();
        if ($existing !== null && $existing->company_id !== $companyId) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe invoice belongs to another company.', 409);
        }
        if ($existing === null) {
            DB::table('billing_documents')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId, 'subscription_id' => $subscription->id,
                'provider_document_id' => $id, 'document_type' => 'INVOICE',
                'provider_status' => $providerStatus,
                'currency' => $subscription->billing_currency, 'subtotal_minor' => $subtotal,
                'tax_minor' => $tax, 'total_minor' => $total, 'paid_minor' => $paid,
                'line_snapshot' => json_encode($lines, JSON_THROW_ON_ERROR),
                'issued_at' => isset($invoice['created']) && is_int($invoice['created'])
                    ? CarbonImmutable::createFromTimestampUTC($invoice['created']) : null,
                'paid_at' => isset($invoice['status_transitions']['paid_at']) && is_int($invoice['status_transitions']['paid_at'])
                    ? CarbonImmutable::createFromTimestampUTC($invoice['status_transitions']['paid_at']) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } elseif ($existing->provider_status !== 'paid' && ($invoice['status'] ?? null) === 'paid') {
            DB::table('billing_documents')->where('id', $existing->id)->update([
                'provider_status' => 'paid', 'paid_minor' => $paid,
                'paid_at' => isset($invoice['status_transitions']['paid_at']) && is_int($invoice['status_transitions']['paid_at'])
                    ? CarbonImmutable::createFromTimestampUTC($invoice['status_transitions']['paid_at']) : now(),
                'updated_at' => now(),
            ]);
        }
    }
}
