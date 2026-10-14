<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Contracts\BillingGateway;
use App\Exceptions\PlatformException;
use App\Http\Controllers\Controller;
use App\Models\AiUsageRecord;
use App\Models\BillingCataloguePrice;
use App\Models\BillingCheckoutAttempt;
use App\Models\BillingComponentSubscription;
use App\Models\BillingPlanChange;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Subscription;
use App\Services\AuditService;
use App\Services\Platform\BillingCancellationService;
use App\Services\Platform\BillingCatalogueService;
use App\Services\Platform\BillingCheckoutService;
use App\Services\Platform\BillingComponentService;
use App\Services\Platform\BillingPlanChangeService;
use App\Services\Platform\BillingReconciliationService;
use App\Services\Platform\CommercialStateService;
use App\Services\Platform\EntitlementService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingCatalogueService $catalogue,
        private readonly BillingCancellationService $cancellation,
        private readonly BillingGateway $gateway,
        private readonly BillingCheckoutService $checkout,
        private readonly BillingComponentService $components,
        private readonly BillingPlanChangeService $planChanges,
        private readonly BillingReconciliationService $reconciliation,
        private readonly CommercialStateService $commercialState,
        private readonly EntitlementService $entitlements,
        private readonly PlatformAccessService $access,
        private readonly AuditService $audit,
    ) {}

    public function catalogue(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.view');
        $settings = CompanySetting::query()->where('company_id', $companyId)->firstOrFail();
        $confirmed = $settings->billing_country_confirmed_at !== null;
        $currency = $confirmed ? $this->catalogue->billingJurisdiction($companyId)['currency'] : null;

        return response()->json([
            'country_code' => $confirmed ? $settings->country_code : null,
            'billing_currency' => $currency,
            'country_confirmation_required' => ! $confirmed,
            'provider_ready' => $this->providerReady(),
            'prices' => $currency === null ? [] : $this->catalogue->currentPrices($currency)
                ->map(fn (BillingCataloguePrice $price): array => [
                    'kind' => $price->kind, 'code' => $price->code, 'currency' => $price->currency,
                    'billing_interval' => $price->billing_interval, 'version' => $price->version,
                    'unit_amount_minor' => $price->unit_amount_minor, 'is_purchasable' => $price->is_purchasable,
                    'provider_price_configured' => filled($price->provider_price_id),
                    'checkout_available' => $this->providerReady() && $price->is_purchasable
                        && filled($price->provider_price_id),
                ]),
            'included_limits' => [
                'starter' => $this->catalogue->includedLimits('starter'),
                'professional' => $this->catalogue->includedLimits('professional'),
                'enterprise' => $this->catalogue->includedLimits('enterprise'),
            ],
            'additional_storage_available' => false,
            'prices_exclude_tax' => true,
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.view');
        $company = Company::query()->findOrFail($companyId);
        $settings = CompanySetting::query()->where('company_id', $companyId)->firstOrFail();
        $subscription = Subscription::query()->with('plan:id,code,name')->where('company_id', $companyId)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $attempt = BillingCheckoutAttempt::query()->where('company_id', $companyId)
            ->orderByDesc('created_at')->orderByDesc('id')->first();

        return response()->json([
            'commercial' => $this->commercialState->describe($company),
            'country_code' => $settings->billing_country_confirmed_at === null ? null : $settings->country_code,
            'billing_currency' => $settings->billing_country_confirmed_at === null ? null
                : $this->catalogue->billingJurisdiction($companyId)['currency'],
            'subscription' => $subscription === null ? null : [
                'id' => $subscription->id, 'plan' => $subscription->plan?->code,
                'status' => $subscription->status, 'billing_interval' => $subscription->billing_interval,
                'renews_at' => $subscription->renews_at?->toISOString(),
                'cancel_at_period_end_at' => $subscription->cancel_at_period_end_at?->toISOString(),
            ],
            'latest_checkout' => $attempt === null ? null : [
                'id' => $attempt->id, 'status' => $attempt->status, 'created_at' => $attempt->created_at?->toISOString(),
            ],
            'components' => BillingComponentSubscription::query()->where('company_id', $companyId)
                ->orderBy('kind')->orderBy('code')->get()->map(fn (BillingComponentSubscription $component): array => [
                    'id' => $component->id, 'kind' => $component->kind, 'code' => $component->code,
                    'quantity' => $component->quantity, 'scheduled_quantity' => $component->scheduled_quantity,
                    'billing_interval' => $component->billing_interval,
                    'status' => $component->status, 'paid_through_at' => $component->paid_through_at->toISOString(),
                    'cancel_at_period_end_at' => $component->cancel_at_period_end_at?->toISOString(),
                ]),
            'plan_changes' => BillingPlanChange::query()->where('company_id', $companyId)
                ->orderByDesc('created_at')->limit(10)->get()->map(fn (BillingPlanChange $change): array => [
                    'id' => $change->id, 'operation' => $change->operation,
                    'source_plan' => $change->source_plan_code, 'target_plan' => $change->target_plan_code,
                    'target_billing_interval' => $change->target_billing_interval,
                    'status' => $change->status, 'effective_at' => $change->effective_at?->toISOString(),
                ]),
            'provider_ready' => $this->providerReady(),
            'capabilities' => ['manage' => $company->created_by === $request->user()->id
                || $request->user()->hasCompanyPermission($companyId, 'billing.manage')],
        ]);
    }

    public function usage(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.view');
        $company = Company::query()->findOrFail($companyId);
        $subscription = Subscription::query()->with('plan:id,code')->where('company_id', $companyId)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $plan = $subscription?->status === 'TRIALING' ? 'professional' : $subscription?->plan?->code;
        $limits = $company->billing_policy_applies && in_array($plan, ['starter', 'professional', 'enterprise'], true)
            ? $this->catalogue->includedLimits($plan) : null;
        $paidUserSeats = $subscription === null ? 0 : $this->entitlements->paidSeatQuantity($companyId, $subscription, 'users');
        $paidEmployeeSeats = $subscription === null ? 0 : $this->entitlements->paidSeatQuantity($companyId, $subscription, 'employees');
        if ($limits !== null && ($paidUserSeats > PHP_INT_MAX - $limits['users']
            || $paidEmployeeSeats > PHP_INT_MAX - $limits['employees'])) {
            throw new PlatformException('SUBSCRIPTION_USAGE_INVALID', 'Subscription capacity is outside the supported range.', 409);
        }
        $activeMembers = CompanyUser::query()->where('company_id', $companyId)->where('is_active', true)->count();
        $pendingInvitations = CompanyInvitation::query()->where('company_id', $companyId)
            ->where('status', 'PENDING')->where('expires_at', '>', now())->count();
        $activeEmployees = Employee::query()->where('company_id', $companyId)
            ->whereIn('status', ['active', 'probation', 'on_leave', 'notice_period'])->count();
        $storageBytes = (int) Document::query()->where('company_id', $companyId)->sum('size_bytes');
        $monthlyUsage = AiUsageRecord::query()->where('company_id', $companyId)
            ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()]);
        $aiTokens = (int) (clone $monthlyUsage)->sum('input_tokens')
            + (int) (clone $monthlyUsage)->sum('output_tokens');
        $aiReserved = (int) DB::table('ai_usage_reservations')->where('company_id', $companyId)
            ->where('period_start', now()->startOfMonth()->toDateString())
            ->where('status', 'PENDING')->where('expires_at', '>', now())->sum('reserved_tokens');
        $aiAllowance = $company->billing_policy_applies
            && in_array('ai', $this->entitlements->enabledModules($companyId), true)
                ? (int) config('services.billing.ai_monthly_token_allowance') : null;

        return response()->json([
            'managed_billing' => $company->billing_policy_applies,
            'users' => ['active' => $activeMembers, 'pending_invitations' => $pendingInvitations,
                'reserved_total' => $activeMembers + $pendingInvitations, 'included' => $limits['users'] ?? null,
                'paid_overage' => $paidUserSeats,
                'capacity' => $limits === null ? null : $limits['users'] + $paidUserSeats,
                'over_limit' => $limits === null ? null : $activeMembers + $pendingInvitations > $limits['users'] + $paidUserSeats],
            'employees' => ['active' => $activeEmployees, 'included' => $limits['employees'] ?? null,
                'paid_overage' => $paidEmployeeSeats,
                'capacity' => $limits === null ? null : $limits['employees'] + $paidEmployeeSeats,
                'over_limit' => $limits === null ? null : $activeEmployees > $limits['employees'] + $paidEmployeeSeats],
            'storage' => ['used_bytes' => $storageBytes,
                'included_bytes' => $limits === null ? null : $limits['storage_mb'] * 1_048_576,
                'over_limit' => $limits === null ? null : $storageBytes > $limits['storage_mb'] * 1_048_576,
                'additional_storage_available' => false],
            'ai' => ['period' => now()->format('Y-m'), 'used_tokens' => $aiTokens,
                'reserved_tokens' => $aiReserved, 'included_tokens' => $aiAllowance,
                'remaining_tokens' => $aiAllowance === null ? null : max(0, $aiAllowance - $aiTokens - $aiReserved),
                'paid_overage_available' => false],
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.view');
        $data = $request->validate([
            'plan' => ['required', 'in:starter,professional,enterprise'],
            'billing_interval' => ['required', 'in:monthly,annual'],
            'addons' => ['sometimes', 'array', 'max:5'],
            'addons.*' => ['string', 'in:fbr,crm,inventory,ai,hrm_payroll'],
        ]);

        return response()->json($this->catalogue->quote($companyId, $data['plan'], $data['billing_interval'], $data['addons'] ?? []));
    }

    public function confirmCountry(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.manage');
        $data = $request->validate(['country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/']]);
        $settings = DB::transaction(function () use ($request, $companyId, $data): CompanySetting {
            $settings = CompanySetting::query()->where('company_id', $companyId)->lockForUpdate()->firstOrFail();
            if ($settings->billing_country_confirmed_at !== null && $settings->country_code === $data['country_code']) {
                return $settings;
            }
            $hasProviderActivity = Subscription::query()->where('company_id', $companyId)->whereNotNull('provider_reference')->exists()
                || DB::table('billing_checkout_attempts')->where('company_id', $companyId)->exists();
            if ($hasProviderActivity) {
                throw new PlatformException('BILLING_COUNTRY_CHANGE_REQUIRES_REVIEW', 'Billing country cannot change after provider activity without a controlled transition.', 409);
            }
            $oldCountry = $settings->billing_country_confirmed_at === null ? null : $settings->country_code;
            $settings->update(['country_code' => $data['country_code'], 'billing_country_confirmed_at' => now(), 'updated_by' => $request->user()->id]);
            $this->audit->recordOperation($request->user(), $companyId, 'billing_country_confirmed', 'platform', $settings,
                ['country_code' => $oldCountry], ['country_code' => $data['country_code']]);

            return $settings;
        });

        return response()->json(['country_code' => $settings->country_code,
            'billing_currency' => $this->catalogue->billingJurisdiction($companyId)['currency'],
            'confirmed_at' => $settings->billing_country_confirmed_at->toISOString()]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.manage');
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $key)) {
            throw new PlatformException('BILLING_IDEMPOTENCY_KEY_REQUIRED', 'A valid Idempotency-Key header is required.', 422);
        }
        $data = $request->validate([
            'plan' => ['required', 'in:starter,professional,enterprise'],
            'billing_interval' => ['required', 'in:monthly,annual'],
            'addons' => ['sometimes', 'array', 'max:5'],
            'addons.*' => ['string', 'in:fbr,crm,inventory,ai,hrm_payroll'],
        ]);

        return response()->json($this->checkout->begin($companyId, $request->user(), $data['plan'],
            $data['billing_interval'], $data['addons'] ?? [], $key));
    }

    public function reconcile(Request $request, string $attempt): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.manage');

        $checkout = BillingCheckoutAttempt::query()->where('company_id', $companyId)->findOrFail($attempt);
        if ($checkout->operation === 'COMPONENT_CHECKOUT') {
            return response()->json($this->components->reconcile($companyId, $attempt));
        }

        return response()->json($this->reconciliation->reconcile($companyId, $attempt));
    }

    public function componentCheckout(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.manage');
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $key)) {
            throw new PlatformException('BILLING_IDEMPOTENCY_KEY_REQUIRED', 'A valid Idempotency-Key header is required.', 422);
        }
        $data = $request->validate([
            'kind' => ['required', 'in:addon,seat'], 'code' => ['required', 'string', 'max:60'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        return response()->json($this->components->begin($companyId, $request->user(), $data['kind'],
            $data['code'], (int) $data['quantity'], $key));
    }

    public function cancelComponent(Request $request, string $component): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.manage');

        return response()->json($this->components->cancel($companyId, $request->user(), $component));
    }

    public function reduceSeats(Request $request, string $component): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.manage');
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $key)) {
            throw new PlatformException('BILLING_IDEMPOTENCY_KEY_REQUIRED', 'A valid Idempotency-Key header is required.', 422);
        }
        $data = $request->validate(['target_quantity' => ['required', 'integer', 'min:1', 'max:10000']]);

        return response()->json($this->components->reduceSeats($companyId, $request->user(), $component,
            (int) $data['target_quantity'], $key));
    }

    public function changePlan(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.manage');
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $key)) {
            throw new PlatformException('BILLING_IDEMPOTENCY_KEY_REQUIRED', 'A valid Idempotency-Key header is required.', 422);
        }
        $data = $request->validate([
            'plan' => ['required', 'in:starter,professional,enterprise'],
            'billing_interval' => ['required', 'in:monthly,annual'],
        ]);

        return response()->json($this->planChanges->begin($companyId, $request->user(),
            $data['plan'], $data['billing_interval'], $key));
    }

    public function reconcilePlanChange(Request $request, string $change): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.manage');

        return response()->json($this->planChanges->reconcile($companyId, $change));
    }

    public function cancel(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.manage');

        return response()->json($this->cancellation->cancelAtPeriodEnd($companyId, $request->user()));
    }

    public function history(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.view');
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $perPage = $data['per_page'] ?? 25;
        $documents = DB::table('billing_documents')->where('company_id', $companyId)
            ->orderByDesc('issued_at')->orderByDesc('id')->paginate($perPage, ['id', 'document_type',
                'provider_status', 'currency', 'subtotal_minor', 'discount_minor', 'tax_minor',
                'total_minor', 'paid_minor', 'issued_at', 'paid_at']);
        $subscriptions = Subscription::query()->with('plan:id,code,name')->where('company_id', $companyId)
            ->orderByDesc('created_at')->get()->map(fn (Subscription $subscription): array => [
                'id' => $subscription->id, 'plan' => $subscription->plan?->code,
                'status' => $subscription->status, 'billing_interval' => $subscription->billing_interval,
                'starts_at' => $subscription->starts_at?->toISOString(),
                'renews_at' => $subscription->renews_at?->toISOString(),
                'ends_at' => $subscription->ends_at?->toISOString(),
                'cancel_at_period_end_at' => $subscription->cancel_at_period_end_at?->toISOString(),
            ]);

        $planChanges = BillingPlanChange::query()->where('company_id', $companyId)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage)->through(fn (BillingPlanChange $change): array => [
                'id' => $change->id, 'operation' => $change->operation, 'status' => $change->status,
                'source_plan' => $change->source_plan_code, 'target_plan' => $change->target_plan_code,
                'target_billing_interval' => $change->target_billing_interval, 'currency' => $change->currency,
                'target_amount_minor' => $change->target_amount_minor,
                'effective_at' => $change->effective_at?->toISOString(),
                'created_at' => $change->created_at?->toISOString(),
            ]);
        $components = BillingComponentSubscription::query()->where('company_id', $companyId)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage)->through(fn (BillingComponentSubscription $component): array => [
                'id' => $component->id, 'kind' => $component->kind, 'code' => $component->code,
                'currency' => $component->currency, 'billing_interval' => $component->billing_interval,
                'quantity' => $component->quantity, 'scheduled_quantity' => $component->scheduled_quantity,
                'status' => $component->status, 'paid_through_at' => $component->paid_through_at->toISOString(),
                'cancel_at_period_end_at' => $component->cancel_at_period_end_at?->toISOString(),
                'created_at' => $component->created_at?->toISOString(),
            ]);
        $seatChanges = DB::table('billing_component_quantity_changes')->where('company_id', $companyId)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage,
                ['id', 'component_subscription_id', 'previous_quantity', 'target_quantity', 'status',
                    'effective_at', 'completed_at', 'created_at']);

        return response()->json(['documents' => $documents, 'subscriptions' => $subscriptions,
            'plan_changes' => $planChanges, 'components' => $components, 'seat_changes' => $seatChanges]);
    }

    public function documentAccess(Request $request, string $document): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.view');
        $record = DB::table('billing_documents')->where('company_id', $companyId)->where('id', $document)->first();
        if ($record === null) {
            abort(404);
        }
        $subscription = Subscription::query()->where('company_id', $companyId)
            ->whereKey($record->subscription_id)->firstOrFail();
        $customerId = DB::table('billing_customers')->where('company_id', $companyId)->value('provider_customer_id');
        $invoice = $this->gateway->retrieveInvoice($record->provider_document_id);
        $invoiceSubscription = $invoice['subscription'] ?? ($invoice['parent']['subscription_details']['subscription'] ?? null);
        $expectedProviderSubscriptionId = $record->component_subscription_id === null
            ? $subscription->provider_reference
            : BillingComponentSubscription::query()->where('company_id', $companyId)
                ->where('subscription_id', $subscription->id)->findOrFail($record->component_subscription_id)
                ->provider_subscription_id;
        if (($invoice['id'] ?? null) !== $record->provider_document_id
            || ($invoice['customer'] ?? null) !== $customerId
            || $invoiceSubscription !== $expectedProviderSubscriptionId
            || strtoupper((string) ($invoice['currency'] ?? '')) !== $record->currency) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'The provider document does not belong to this company.', 409);
        }

        return response()->json([
            'id' => $record->id,
            'hosted_invoice_url' => $this->safeProviderDocumentUrl($invoice['hosted_invoice_url'] ?? null, 'invoice.stripe.com'),
            'pdf_url' => $this->safeProviderDocumentUrl($invoice['invoice_pdf'] ?? null, 'pay.stripe.com'),
        ]);
    }

    public function paymentMethodPortal(Request $request): JsonResponse
    {
        $companyId = $this->authorize($request, 'billing.manage');
        $configurationId = config('services.stripe.payment_method_portal_configuration_id');
        $origin = rtrim((string) config('services.stripe.return_origin'), '/');
        if (! $this->providerReady() || ! is_string($configurationId)
            || ! preg_match('/^bpc_[A-Za-z0-9]+$/', $configurationId)
            || (parse_url($origin, PHP_URL_SCHEME) !== 'https'
                && ! (app()->isLocal() && parse_url($origin, PHP_URL_HOST) === 'localhost'
                    && parse_url($origin, PHP_URL_SCHEME) === 'http'))) {
            throw new PlatformException('BILLING_PAYMENT_METHOD_UNAVAILABLE', 'Payment-method management is not configured.', 503);
        }
        $customerId = DB::table('billing_customers')->where('company_id', $companyId)
            ->where('provider', 'stripe')->value('provider_customer_id');
        if (! is_string($customerId) || ! preg_match('/^cus_[A-Za-z0-9]+$/', $customerId)
            || ! Subscription::query()->where('company_id', $companyId)->where('provider', 'stripe')
                ->whereNotNull('provider_reference')->exists()) {
            throw new PlatformException('BILLING_CUSTOMER_UNAVAILABLE', 'This company has no linked Stripe billing customer.', 409);
        }
        $configuration = $this->gateway->retrievePortalConfiguration($configurationId);
        $features = $configuration['features'] ?? null;
        if (($configuration['id'] ?? null) !== $configurationId || ($configuration['active'] ?? null) !== true
            || ! is_array($features) || ($features['payment_method_update']['enabled'] ?? null) !== true
            || ($features['subscription_update']['enabled'] ?? false) !== false
            || ($features['subscription_cancel']['enabled'] ?? false) !== false
            || ($features['subscription_pause']['enabled'] ?? false) !== false
            || ($features['customer_update']['enabled'] ?? false) !== false) {
            throw new PlatformException('BILLING_PAYMENT_METHOD_UNAVAILABLE', 'The configured portal permits unsupported billing changes.', 503);
        }
        $session = $this->gateway->createPaymentMethodPortalSession($customerId, $configurationId, $origin.'/billing');
        if (! is_string($session['id'] ?? null) || ! str_starts_with($session['id'], 'bps_')
            || ($session['customer'] ?? null) !== $customerId
            || $this->safeProviderDocumentUrl($session['url'] ?? null, 'billing.stripe.com') === null) {
            throw new PlatformException('BILLING_PROVIDER_MISMATCH', 'Stripe did not return a valid company payment-method session.', 409);
        }

        return response()->json(['url' => $session['url']]);
    }

    private function safeProviderDocumentUrl(mixed $url, string $host): ?string
    {
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_HOST) !== $host || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null || ! in_array(parse_url($url, PHP_URL_PORT), [null, 443], true)) {
            return null;
        }

        return $url;
    }

    private function providerReady(): bool
    {
        return config('services.stripe.enabled') === true
            && filled(config('services.stripe.secret'))
            && filled(config('services.stripe.webhook_secret'))
            && filled(config('services.stripe.return_origin'));
    }

    private function authorize(Request $request, string $permission): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        $company = Company::query()->findOrFail($companyId);
        if ($company->created_by !== $request->user()->id) {
            $this->access->authorize($request->user(), $companyId, $permission);
        }

        return $companyId;
    }
}
