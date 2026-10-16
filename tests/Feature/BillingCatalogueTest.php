<?php

namespace Tests\Feature;

use App\Contracts\BillingGateway;
use App\Enums\FbrSubmissionStatus;
use App\Exceptions\PlatformException;
use App\Models\AiConversation;
use App\Models\AiUsageRecord;
use App\Models\BillingCataloguePrice;
use App\Models\BillingCheckoutAttempt;
use App\Models\BillingComponentSubscription;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanySetting;
use App\Models\Document;
use App\Models\Employee;
use App\Models\PakistanFbrInvoice;
use App\Models\PakistanFbrSubmissionAttempt;
use App\Models\Plan;
use App\Models\PlatformModule;
use App\Models\Subscription;
use App\Services\Ai\DatabaseAiUsageMeter;
use App\Services\Platform\BillingCatalogueService;
use App\Services\Platform\EntitlementService;
use Database\Seeders\BillingCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BillingCatalogueSeeder::class);
    }

    public function test_approved_catalogue_is_integer_money_versioned_and_unpriced_items_cannot_be_purchased(): void
    {
        $catalogue = app(BillingCatalogueService::class);
        $monthly = $catalogue->price('plan', 'starter', 'PKR', 'monthly');
        $annual = $catalogue->price('plan', 'starter', 'PKR', 'annual');
        $this->assertSame(299_900, $monthly->unit_amount_minor);
        $this->assertSame(3_058_980, $annual->unit_amount_minor);
        $this->assertSame(2_499_900, $catalogue->price('plan', 'enterprise', 'PKR', 'monthly')->unit_amount_minor);
        $this->assertSame(1_500, $catalogue->price('plan', 'starter', 'USD', 'monthly')->unit_amount_minor);
        $this->assertSame(15_300, $catalogue->price('plan', 'starter', 'USD', 'annual')->unit_amount_minor);
        $this->assertSame(50_000, $catalogue->price('seat', 'user_overage', 'PKR', 'monthly')->unit_amount_minor);
        $this->assertSame(['accounting', 'invoicing', 'receivables'], $catalogue->modulesFor('starter'));
        $this->assertSame([], array_values(array_intersect(
            ['procurement', 'payables', 'banking', 'budgeting', 'outreach'], $catalogue->modulesFor('enterprise')
        )));
        $this->assertSame(1, $monthly->version);
        $this->assertSame(2 * 299_900, $catalogue->line($monthly, 2)['total_minor']);
        $this->assertNull(BillingCataloguePrice::query()->where('code', 'storage_block_10gb')->firstOrFail()->unit_amount_minor);

        try {
            $catalogue->price('storage', 'storage_block_10gb', 'PKR', 'monthly');
            $this->fail('Unpriced storage must not be purchasable.');
        } catch (PlatformException $exception) {
            $this->assertSame('BILLING_PRICE_UNAVAILABLE', $exception->errorCode);
        }
    }

    public function test_billing_currency_requires_confirmed_registered_country_and_ignores_base_currency(): void
    {
        $settings = CompanySetting::factory()->create(['country_code' => 'PK', 'base_currency' => 'USD']);
        $catalogue = app(BillingCatalogueService::class);
        try {
            $catalogue->billingJurisdiction($settings->company_id);
            $this->fail('An inherited default country is not confirmed billing authority.');
        } catch (PlatformException $exception) {
            $this->assertSame('BILLING_COUNTRY_REQUIRED', $exception->errorCode);
        }
        $settings->update(['billing_country_confirmed_at' => now()]);
        $this->assertSame(['country_code' => 'PK', 'currency' => 'PKR'], $catalogue->billingJurisdiction($settings->company_id));
        $settings->update(['country_code' => 'DK']);
        $this->assertSame(['country_code' => 'DK', 'currency' => 'USD'], $catalogue->billingJurisdiction($settings->company_id));
        $quote = $catalogue->quote($settings->company_id, 'starter', 'annual', ['fbr', 'inventory']);
        $this->assertSame('USD', $quote['currency']);
        $this->assertSame(15_300 + 10_200 + 15_300, $quote['total_minor']);
        $this->assertSame('EXCLUSIVE_TO_BE_CALCULATED_BY_PROVIDER', $quote['tax_status']);
        $this->assertNull($quote['tax_minor']);
        $this->assertSame(['users' => 10, 'employees' => 25, 'storage_mb' => 25 * 1024], $catalogue->includedLimits('professional'));
        try {
            $catalogue->quote($settings->company_id, 'professional', 'monthly', ['fbr']);
            $this->fail('Included add-ons must not be charged twice.');
        } catch (PlatformException $exception) {
            $this->assertSame('BILLING_ADDON_INCLUDED', $exception->errorCode);
        }
    }

    public function test_catalogue_quote_and_country_confirmation_are_company_scoped_and_provider_disabled(): void
    {
        $context = $this->stage3AccountingContext(['billing.view', 'billing.manage']);
        CompanySetting::factory()->for($context['company'])->create(['country_code' => 'PK']);
        $headers = ['X-Company-Id' => $context['company']->id];
        $this->getJson('/api/v1/platform/billing/catalogue', $headers)->assertOk()
            ->assertJsonPath('country_confirmation_required', true)->assertJsonPath('provider_ready', false);
        $this->postJson('/api/v1/platform/billing/quote', ['plan' => 'starter', 'billing_interval' => 'monthly'], $headers)
            ->assertConflict()->assertJsonPath('error_code', 'BILLING_COUNTRY_REQUIRED');
        $this->putJson('/api/v1/platform/billing/country', ['country_code' => 'PK'], $headers)->assertOk()
            ->assertJsonPath('billing_currency', 'PKR');
        $this->getJson('/api/v1/platform/billing/catalogue', $headers)->assertOk()
            ->assertJsonPath('billing_currency', 'PKR')->assertJsonPath('provider_ready', false);
        $this->postJson('/api/v1/platform/billing/quote', ['plan' => 'starter', 'billing_interval' => 'monthly'], $headers)
            ->assertOk()->assertJsonPath('total_minor', 299_900);
        $this->postJson('/api/v1/platform/billing/checkout', ['plan' => 'starter', 'billing_interval' => 'monthly'],
            [...$headers, 'Idempotency-Key' => 'Billing-1'])
            ->assertStatus(503)->assertJsonPath('error_code', 'BILLING_PROVIDER_NOT_CONFIGURED');
        $this->putJson('/api/v1/platform/billing/country', ['country_code' => 'PK'], $headers)->assertOk();
        $this->assertDatabaseCount('billing_checkout_attempts', 0);

        [, $otherCompany] = $this->actingAsCompanyUser(['billing.view', 'billing.manage']);
        $this->getJson('/api/v1/platform/billing/catalogue', $headers)->assertForbidden();
        $this->getJson('/api/v1/platform/billing/catalogue', ['X-Company-Id' => $otherCompany->id])->assertNotFound();
    }

    public function test_unmanaged_active_subscription_reports_legacy_access_without_enabling_stripe_actions(): void
    {
        [, $company] = $this->actingAsCompanyUser(['billing.view', 'billing.manage', 'accounting.view']);
        CompanySetting::factory()->for($company)->create(['country_code' => 'PK', 'billing_country_confirmed_at' => now()]);
        $module = PlatformModule::factory()->create(['key' => 'accounting']);
        $plan = Plan::factory()->create();
        $plan->modules()->attach($module->key, ['is_enabled' => true]);
        Subscription::factory()->for($company)->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => null, 'provider_reference' => null,
            'renews_at' => now()->addMonth(),
        ]);
        $headers = ['X-Company-Id' => $company->id];

        $this->getJson('/api/v1/platform/billing/summary', $headers)->assertOk()
            ->assertJsonPath('commercial.state', 'ACTIVE')
            ->assertJsonPath('subscription.status', 'ACTIVE')
            ->assertJsonPath('capabilities.manage', true)
            ->assertJsonPath('provider_ready', false);
        $this->getJson('/api/v1/platform/billing/usage', $headers)->assertOk()
            ->assertJsonPath('managed_billing', false);
        $this->getJson('/api/v1/accounting/accounts', $headers)->assertOk();
        $this->postJson('/api/v1/platform/billing/checkout', [
            'plan' => 'starter', 'billing_interval' => 'monthly',
        ], [...$headers, 'Idempotency-Key' => 'LegacyCheckout'])->assertStatus(503)
            ->assertJsonPath('error_code', 'BILLING_PROVIDER_NOT_CONFIGURED');
        $this->postJson('/api/v1/platform/billing/payment-method-portal', [], $headers)
            ->assertStatus(503)->assertJsonPath('error_code', 'BILLING_PAYMENT_METHOD_UNAVAILABLE');
        $this->assertDatabaseCount('billing_checkout_attempts', 0);
    }

    public function test_managed_active_row_without_provider_link_remains_read_only(): void
    {
        [, $company] = $this->actingAsCompanyUser(['billing.view', 'billing.manage']);
        $company->update(['billing_policy_applies' => true]);
        CompanySetting::factory()->for($company)->create();
        $plan = Plan::factory()->create(['code' => 'starter']);
        Subscription::factory()->for($company)->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => null, 'provider_reference' => null,
            'renews_at' => now()->addMonth(),
        ]);

        $this->getJson('/api/v1/platform/billing/summary', ['X-Company-Id' => $company->id])
            ->assertOk()->assertJsonPath('commercial.state', 'READ_ONLY')
            ->assertJsonPath('capabilities.manage', true);
    }

    public function test_managed_provider_linked_subscription_stays_active_when_stripe_is_not_configured(): void
    {
        [, $company] = $this->actingAsCompanyUser(['billing.view', 'billing.manage']);
        $company->update(['billing_policy_applies' => true]);
        CompanySetting::factory()->for($company)->create();
        $plan = Plan::factory()->create(['code' => 'starter']);
        Subscription::factory()->for($company)->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_testLinked',
            'renews_at' => now()->addMonth(),
        ]);

        $this->getJson('/api/v1/platform/billing/summary', ['X-Company-Id' => $company->id])
            ->assertOk()->assertJsonPath('commercial.state', 'ACTIVE')
            ->assertJsonPath('provider_ready', false);
    }

    public function test_unmanaged_expired_subscription_follows_legacy_entitlement_policy(): void
    {
        [, $company] = $this->actingAsCompanyUser(['billing.view', 'accounting.view']);
        CompanySetting::factory()->for($company)->create();
        $module = PlatformModule::factory()->create(['key' => 'accounting']);
        $plan = Plan::factory()->create();
        $plan->modules()->attach($module->key, ['is_enabled' => true]);
        Subscription::factory()->for($company)->for($plan)->create([
            'status' => 'TRIALING', 'trial_ends_at' => now()->subDay(),
        ]);
        $headers = ['X-Company-Id' => $company->id];

        $this->getJson('/api/v1/platform/billing/summary', $headers)->assertOk()
            ->assertJsonPath('commercial.state', 'READ_ONLY')
            ->assertJsonPath('capabilities.manage', false);
        $this->getJson('/api/v1/accounting/accounts', $headers)->assertForbidden()
            ->assertJsonPath('error_code', 'SUBSCRIPTION_INACTIVE');
    }

    public function test_billing_management_capability_remains_permission_and_company_scoped(): void
    {
        [, $companyA] = $this->actingAsCompanyUser(['billing.view', 'billing.manage']);
        CompanySetting::factory()->for($companyA)->create();
        [, $companyB] = $this->actingAsCompanyUser(['billing.view']);
        CompanySetting::factory()->for($companyB)->create();

        $this->getJson('/api/v1/platform/billing/summary', ['X-Company-Id' => $companyB->id])
            ->assertOk()->assertJsonPath('capabilities.manage', false);
        $this->getJson('/api/v1/platform/billing/summary', ['X-Company-Id' => $companyA->id])
            ->assertForbidden()->assertJsonPath('error_code', 'TENANT_ACCESS_DENIED');
        [, $companyC] = $this->actingAsCompanyUser(['billing.manage']);
        CompanySetting::factory()->for($companyC)->create();
        $this->getJson('/api/v1/platform/billing/summary', ['X-Company-Id' => $companyC->id])
            ->assertForbidden()->assertJsonPath('error_code', 'PERMISSION_DENIED');
    }

    public function test_fake_checkout_creates_payment_pending_only_and_replay_reuses_provider_session(): void
    {
        config()->set('services.stripe.enabled', true);
        config()->set('services.stripe.secret', 'test-only-placeholder');
        config()->set('services.stripe.webhook_secret', 'test-only-placeholder');
        config()->set('services.stripe.return_origin', 'https://example.test');
        $context = $this->stage3AccountingContext(['billing.view', 'billing.manage']);
        CompanySetting::factory()->for($context['company'])->create([
            'email' => 'billing@example.test', 'country_code' => 'PK', 'billing_country_confirmed_at' => now(),
        ]);
        BillingCataloguePrice::query()->where('kind', 'plan')->where('code', 'starter')->where('currency', 'PKR')
            ->where('billing_interval', 'monthly')->update(['provider_price_id' => 'price_testStarter']);
        BillingCataloguePrice::query()->where('kind', 'addon')->where('code', 'fbr')->where('currency', 'PKR')
            ->where('billing_interval', 'monthly')->update(['provider_price_id' => 'price_testFbr']);
        $gateway = $this->mock(BillingGateway::class);
        $gateway->shouldReceive('retrievePrice')->andReturnUsing(fn (string $priceId): array => [
            'id' => $priceId, 'active' => true, 'currency' => 'pkr',
            'unit_amount' => $priceId === 'price_testFbr' ? 250_000 : 299_900,
            'recurring' => ['interval' => 'month'],
        ]);
        $gateway->shouldReceive('createCheckout')->once()->andReturn([
            'id' => 'cs_test_checkout', 'url' => 'https://checkout.stripe.com/test-session',
        ]);
        $gateway->shouldReceive('retrieveCheckout')->once()->andReturnUsing(function (string $sessionId): array {
            $attemptId = BillingCheckoutAttempt::query()->where('provider_session_id', $sessionId)->value('id');

            return ['id' => $sessionId, 'client_reference_id' => $attemptId,
                'url' => 'https://checkout.stripe.com/test-session'];
        });
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'Billing-1'];
        $first = $this->postJson('/api/v1/platform/billing/checkout', [
            'plan' => 'starter', 'billing_interval' => 'monthly',
        ], $headers)->assertOk()->assertJsonPath('status', 'PAYMENT_PENDING')
            ->assertJsonPath('checkout_url', 'https://checkout.stripe.com/test-session')->json('attempt_id');
        $this->getJson('/api/v1/platform/billing/summary', $headers)->assertOk()
            ->assertJsonPath('commercial.state', 'PAYMENT_PENDING')->assertJsonPath('latest_checkout.id', $first);
        $this->postJson('/api/v1/platform/billing/checkout', [
            'plan' => 'starter', 'billing_interval' => 'monthly',
        ], $headers)->assertOk()->assertJsonPath('attempt_id', $first);
        $this->postJson('/api/v1/platform/billing/checkout', [
            'plan' => 'starter', 'billing_interval' => 'monthly', 'addons' => ['fbr'],
        ], $headers)->assertConflict()->assertJsonPath('error_code', 'BILLING_IDEMPOTENCY_CONFLICT');
        $this->postJson('/api/v1/platform/billing/checkout', [
            'plan' => 'starter', 'billing_interval' => 'monthly', 'addons' => ['fbr'],
        ], ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'Billing-addon-separate'])
            ->assertConflict()->assertJsonPath('error_code', 'BILLING_ADDON_SEPARATE_CHECKOUT_REQUIRED');
        $this->postJson('/api/v1/platform/billing/checkout', [
            'plan' => 'starter', 'billing_interval' => 'monthly',
        ], ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'Billing-2'])
            ->assertConflict()->assertJsonPath('error_code', 'BILLING_CHECKOUT_ALREADY_PENDING');
        $this->assertDatabaseCount('billing_checkout_attempts', 1);
        $this->assertDatabaseMissing('subscriptions', ['company_id' => $context['company']->id, 'status' => 'ACTIVE', 'provider' => 'stripe']);
    }

    public function test_paid_subscription_activates_only_after_authoritative_fake_provider_reconciliation(): void
    {
        config()->set('services.stripe.enabled', true);
        config()->set('services.stripe.secret', 'test-only-placeholder');
        config()->set('services.stripe.webhook_secret', 'test-only-placeholder');
        config()->set('services.stripe.return_origin', 'https://example.test');
        $context = $this->stage3AccountingContext(['billing.view', 'billing.manage']);
        CompanySetting::factory()->for($context['company'])->create([
            'email' => 'billing@example.test', 'country_code' => 'PK', 'billing_country_confirmed_at' => now(),
        ]);
        Plan::factory()->create(['code' => 'starter']);
        BillingCataloguePrice::query()->where('kind', 'plan')->where('code', 'starter')->where('currency', 'PKR')
            ->where('billing_interval', 'monthly')->update(['provider_price_id' => 'price_testStarter']);
        $gateway = $this->mock(BillingGateway::class);
        $gateway->shouldReceive('retrievePrice')->once()->andReturn([
            'id' => 'price_testStarter', 'active' => true, 'currency' => 'pkr',
            'unit_amount' => 299_900, 'recurring' => ['interval' => 'month'],
        ]);
        $gateway->shouldReceive('createCheckout')->once()->andReturn([
            'id' => 'cs_test_confirmed', 'url' => 'https://checkout.stripe.com/test-confirmed',
        ]);
        $gateway->shouldReceive('retrieveCheckout')->once()->andReturnUsing(function (string $sessionId): array {
            $attemptId = BillingCheckoutAttempt::query()->where('provider_session_id', $sessionId)->value('id');

            return ['id' => $sessionId, 'client_reference_id' => $attemptId, 'status' => 'complete',
                'payment_status' => 'paid', 'currency' => 'pkr', 'amount_subtotal' => 299_900,
                'customer' => 'cus_testCompany', 'subscription' => 'sub_testCompany'];
        });
        $gateway->shouldReceive('retrieveSubscription')->once()->andReturn([
            'id' => 'sub_testCompany', 'customer' => 'cus_testCompany', 'status' => 'active',
            'current_period_end' => now()->addMonth()->timestamp,
            'items' => ['data' => [['quantity' => 1, 'price' => ['id' => 'price_testStarter', 'currency' => 'pkr']]]],
        ]);
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'Billing-paid'];
        $attemptId = $this->postJson('/api/v1/platform/billing/checkout', [
            'plan' => 'starter', 'billing_interval' => 'monthly',
        ], $headers)->assertOk()->json('attempt_id');
        $this->assertDatabaseMissing('subscriptions', ['company_id' => $context['company']->id, 'provider' => 'stripe', 'status' => 'ACTIVE']);
        $path = "/api/v1/platform/billing/checkout/{$attemptId}/reconcile";
        $first = $this->postJson($path, [], $headers)->assertOk()->assertJsonPath('status', 'ACTIVE')->json('subscription_id');
        $this->postJson($path, [], $headers)->assertOk()->assertJsonPath('subscription_id', $first);
        $this->assertDatabaseHas('subscriptions', ['id' => $first, 'company_id' => $context['company']->id,
            'provider' => 'stripe', 'status' => 'ACTIVE', 'billing_currency' => 'PKR']);
        $this->assertDatabaseCount('billing_subscription_items', 1);
        $this->assertDatabaseCount('billing_customers', 1);
        foreach (['invoices', 'journals', 'journal_lines', 'customer_payments', 'inventory_movements', 'bank_reconciliations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_checkout_rejects_misconfigured_provider_price_before_creating_an_attempt(): void
    {
        config()->set('services.stripe.enabled', true);
        config()->set('services.stripe.secret', 'test-only-placeholder');
        config()->set('services.stripe.webhook_secret', 'test-only-placeholder');
        config()->set('services.stripe.return_origin', 'https://example.test');
        $context = $this->stage3AccountingContext(['billing.manage']);
        CompanySetting::factory()->for($context['company'])->create([
            'email' => 'billing@example.test', 'country_code' => 'PK', 'billing_country_confirmed_at' => now(),
        ]);
        BillingCataloguePrice::query()->where('kind', 'plan')->where('code', 'starter')->where('currency', 'PKR')
            ->where('billing_interval', 'monthly')->update(['provider_price_id' => 'price_wrongAmount']);
        $gateway = $this->mock(BillingGateway::class);
        $gateway->shouldReceive('retrievePrice')->once()->andReturn([
            'id' => 'price_wrongAmount', 'active' => true, 'currency' => 'pkr',
            'unit_amount' => 100, 'recurring' => ['interval' => 'month'],
        ]);
        $gateway->shouldNotReceive('createCheckout');
        $this->postJson('/api/v1/platform/billing/checkout', [
            'plan' => 'starter', 'billing_interval' => 'monthly',
        ], ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'WrongPrice'])
            ->assertConflict()->assertJsonPath('error_code', 'BILLING_PRICE_MISMATCH');
        $this->assertDatabaseCount('billing_checkout_attempts', 0);
    }

    public function test_expired_trial_is_server_enforced_read_only_but_billing_recovery_and_history_stay_available(): void
    {
        $context = $this->stage3AccountingContext(['billing.view', 'billing.manage', 'pakistan_fbr.view', 'pakistan_fbr.manage']);
        $context['company']->update(['billing_policy_applies' => true]);
        CompanySetting::factory()->for($context['company'])->create([
            'email' => 'billing@example.test', 'country_code' => 'PK', 'billing_country_confirmed_at' => now(),
        ]);
        $plan = Plan::factory()->create(['code' => 'professional']);
        Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'TRIALING', 'trial_ends_at' => now()->subMinute(),
        ]);
        $headers = ['X-Company-Id' => $context['company']->id];
        $this->getJson('/api/v1/platform/billing/summary', $headers)->assertOk()
            ->assertJsonPath('commercial.state', 'READ_ONLY');
        $this->getJson('/api/v1/pakistan-fbr/invoices', $headers)->assertOk();
        $this->postJson('/api/v1/pakistan-fbr/invoices', [], $headers)
            ->assertForbidden()->assertJsonPath('error_code', 'COMPANY_READ_ONLY');
        $this->postJson('/api/v1/platform/billing/quote', [
            'plan' => 'starter', 'billing_interval' => 'monthly',
        ], $headers)->assertOk()->assertJsonPath('total_minor', 299_900);
        $this->postJson('/api/v1/platform/billing/checkout', [
            'plan' => 'starter', 'billing_interval' => 'monthly',
        ], [...$headers, 'Idempotency-Key' => 'Recover-1'])
            ->assertStatus(503)->assertJsonPath('error_code', 'BILLING_PROVIDER_NOT_CONFIGURED');
        $this->postJson('/api/v1/platform/billing/payment-method-portal', [], $headers)
            ->assertStatus(503)->assertJsonPath('error_code', 'BILLING_PAYMENT_METHOD_UNAVAILABLE');
    }

    public function test_expired_trial_permits_only_authorized_uncertain_fbr_reconciliation_not_new_mutations(): void
    {
        $context = $this->stage3AccountingContext(['pakistan_fbr.view', 'pakistan_fbr.manage', 'fbr.reconcile']);
        $context['company']->update(['billing_policy_applies' => true]);
        $plan = Plan::factory()->create(['code' => 'professional']);
        Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'TRIALING', 'trial_ends_at' => now()->subMinute(),
        ]);
        $invoice = PakistanFbrInvoice::factory()->for($context['company'])->create([
            'fbr_status' => FbrSubmissionStatus::Uncertain, 'document_state' => 'DRAFT',
        ]);
        PakistanFbrSubmissionAttempt::factory()->for($invoice, 'invoice')->create([
            'company_id' => $context['company']->id, 'submitted_by' => $context['user']->id,
            'status' => FbrSubmissionStatus::Uncertain,
        ]);
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'expired-trial-reconcile'];

        $this->postJson("/api/v1/pakistan-fbr/invoices/{$invoice->id}/resolve", [
            'outcome' => 'REJECTED', 'evidence_note' => 'Authoritative rejection independently reviewed',
        ], $headers)->assertOk()->assertJsonPath('fbr_status', 'rejected');
        $this->postJson('/api/v1/pakistan-fbr/invoices', [], $headers)
            ->assertForbidden()->assertJsonPath('error_code', 'COMPANY_READ_ONLY');
        $this->assertDatabaseCount('pakistan_fbr_invoice_reconciliations', 1);
    }

    public function test_paid_starter_has_native_invoicing_without_fbr_until_paid_fbr_addon_is_recorded(): void
    {
        $context = $this->stage3AccountingContext(['billing.view', 'pakistan_fbr.view']);
        $context['company']->update(['billing_policy_applies' => true]);
        $plan = Plan::factory()->create(['code' => 'starter']);
        $subscription = Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_fake_authoritative',
            'renews_at' => now()->addMonth(),
        ]);
        $entitlements = app(EntitlementService::class);
        $this->assertContains('invoicing', $entitlements->enabledModules($context['company']->id));
        $this->assertNotContains('fbr', $entitlements->enabledModules($context['company']->id));
        $this->getJson('/api/v1/pakistan-fbr/invoices', ['X-Company-Id' => $context['company']->id])
            ->assertForbidden()->assertJsonPath('error_code', 'MODULE_NOT_ENTITLED');
        DB::table('billing_subscription_items')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $context['company']->id,
            'subscription_id' => $subscription->id, 'kind' => 'addon', 'code' => 'fbr',
            'currency' => 'PKR', 'billing_interval' => 'monthly', 'unit_amount_minor' => 250_000,
            'quantity' => 1, 'total_minor' => 250_000, 'effective_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $entitlements->forget($context['company']->id);
        $this->assertNotContains('fbr', $entitlements->enabledModules($context['company']->id));
        $foreignCompany = Company::factory()->create();
        DB::table('billing_subscription_items')->where('subscription_id', $subscription->id)
            ->where('kind', 'addon')->update(['company_id' => $foreignCompany->id, 'effective_at' => now()->subDay()]);
        $entitlements->forget($context['company']->id);
        $this->assertNotContains('fbr', $entitlements->enabledModules($context['company']->id));
        DB::table('billing_subscription_items')->where('subscription_id', $subscription->id)
            ->where('kind', 'addon')->delete();
        DB::table('billing_subscription_items')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $context['company']->id,
            'subscription_id' => $subscription->id, 'kind' => 'addon', 'code' => 'fbr',
            'currency' => 'PKR', 'billing_interval' => 'monthly', 'unit_amount_minor' => 250_000,
            'quantity' => 1, 'total_minor' => 250_000, 'effective_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $entitlements->forget($context['company']->id);
        $this->assertContains('fbr', $entitlements->enabledModules($context['company']->id));
        $this->getJson('/api/v1/pakistan-fbr/invoices', ['X-Company-Id' => $context['company']->id])->assertOk();
    }

    public function test_trial_uses_professional_seat_and_storage_limits_with_full_ai_and_inventory_modules(): void
    {
        $context = $this->stage3AccountingContext([]);
        $context['company']->update(['billing_policy_applies' => true]);
        $plan = Plan::factory()->create(['code' => 'starter']);
        Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'TRIALING', 'trial_ends_at' => now()->addDays(7),
        ]);
        $entitlements = app(EntitlementService::class);
        $this->assertContains('ai', $entitlements->enabledModules($context['company']->id));
        $this->assertContains('inventory', $entitlements->enabledModules($context['company']->id));
        $entitlements->assertWithinLimit($context['company']->id, 'users', 9);
        $entitlements->assertWithinLimit($context['company']->id, 'monthly_ai_tokens', 49_999);
        $entitlements->assertWithinLimit($context['company']->id, 'document_storage_mb', 25 * 1024 * 1_048_576 - 1, 1, 1_048_576);
        foreach ([['users', 10, 1, 1], ['monthly_ai_tokens', 50_000, 1, 1],
            ['document_storage_mb', 25 * 1024 * 1_048_576, 1, 1_048_576]] as [$key, $used, $additional, $scale]) {
            try {
                $entitlements->assertWithinLimit($context['company']->id, $key, $used, $additional, $scale);
                $this->fail('The approved commercial allowance must be enforced.');
            } catch (PlatformException $exception) {
                $this->assertSame('SUBSCRIPTION_LIMIT_REACHED', $exception->errorCode);
            }
        }
    }

    public function test_only_current_paid_monthly_seat_items_extend_managed_capacity(): void
    {
        $context = $this->stage3AccountingContext(['billing.view']);
        $context['company']->update(['billing_policy_applies' => true]);
        $plan = Plan::factory()->create(['code' => 'starter']);
        $subscription = Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_paidSeats',
            'renews_at' => now()->addMonth(),
        ]);
        foreach ([['user_overage', 2, null], ['employee_overage', 3, null],
            ['user_overage', 50, now()->subDay()]] as [$code, $quantity, $endsAt]) {
            DB::table('billing_subscription_items')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $context['company']->id,
                'subscription_id' => $subscription->id, 'kind' => 'seat', 'code' => $code,
                'currency' => 'PKR', 'billing_interval' => 'monthly', 'unit_amount_minor' => 25_000,
                'quantity' => $quantity, 'total_minor' => 25_000 * $quantity,
                'effective_at' => now()->subWeek(), 'ends_at' => $endsAt,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $entitlements = app(EntitlementService::class);
        $otherCompany = Company::factory()->create();
        $this->assertSame(0, $entitlements->paidSeatQuantity($otherCompany->id, $subscription, 'users'));
        $entitlements->assertWithinLimit($context['company']->id, 'users', 4);
        $entitlements->assertWithinLimit($context['company']->id, 'employees', 7);
        foreach ([['users', 5], ['employees', 8]] as [$key, $currentUsage]) {
            try {
                $entitlements->assertWithinLimit($context['company']->id, $key, $currentUsage);
                $this->fail('Only provider-confirmed paid seat capacity may be used.');
            } catch (PlatformException $exception) {
                $this->assertSame('SUBSCRIPTION_LIMIT_REACHED', $exception->errorCode);
            }
        }
        $this->getJson('/api/v1/platform/billing/usage', ['X-Company-Id' => $context['company']->id])
            ->assertOk()->assertJsonPath('users.included', 3)->assertJsonPath('users.paid_overage', 2)
            ->assertJsonPath('users.capacity', 5)->assertJsonPath('employees.included', 5)
            ->assertJsonPath('employees.paid_overage', 3)->assertJsonPath('employees.capacity', 8);
    }

    public function test_period_end_cancellation_requires_provider_confirmation_and_preserves_paid_access(): void
    {
        $context = $this->stage3AccountingContext(['billing.manage', 'billing.view']);
        $context['company']->update(['billing_policy_applies' => true]);
        $plan = Plan::factory()->create(['code' => 'starter']);
        $subscription = Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_cancelTest',
            'billing_currency' => 'PKR', 'renews_at' => now()->addMonth(),
        ]);
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $context['company']->id,
            'provider' => 'stripe', 'provider_customer_id' => 'cus_cancelTest',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $attempt = BillingCheckoutAttempt::factory()->for($context['company'])->create([
            'operation' => 'COMPONENT_CHECKOUT', 'status' => 'ACTIVE',
        ]);
        $component = BillingComponentSubscription::factory()->for($context['company'])->create([
            'subscription_id' => $subscription->id, 'checkout_attempt_id' => $attempt->id,
            'catalogue_price_id' => BillingCataloguePrice::query()->where('kind', 'addon')->where('code', 'fbr')
                ->where('currency', 'PKR')->where('billing_interval', 'monthly')->firstOrFail()->id,
            'provider_subscription_id' => 'sub_cancelAddon', 'paid_through_at' => now()->addMonth()->startOfSecond(),
        ]);
        $gateway = $this->mock(BillingGateway::class);
        $gateway->shouldReceive('cancelAtPeriodEnd')->once()->with('sub_cancelAddon', 'zavsync-component-cancel:'.$component->id)
            ->andReturn([
                'id' => 'sub_cancelAddon', 'customer' => 'cus_cancelTest', 'status' => 'active',
                'cancel_at_period_end' => true, 'current_period_end' => $component->paid_through_at->timestamp,
            ]);
        $gateway->shouldReceive('cancelAtPeriodEnd')->once()->with('sub_cancelTest', 'zavsync-cancel:'.$subscription->id)->andReturn([
            'id' => 'sub_cancelTest', 'customer' => 'cus_cancelTest', 'status' => 'active',
            'cancel_at_period_end' => true, 'current_period_end' => now()->addMonth()->timestamp,
        ]);
        $headers = ['X-Company-Id' => $context['company']->id];
        $this->postJson('/api/v1/platform/billing/cancel', [], $headers)->assertOk()
            ->assertJsonPath('status', 'CANCEL_AT_PERIOD_END');
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id, 'status' => 'ACTIVE']);
        $this->assertNotNull($subscription->fresh()->cancel_at_period_end_at);
        $this->assertNotNull($component->fresh()->cancel_at_period_end_at);
        $this->getJson('/api/v1/platform/billing/history', $headers)->assertOk()
            ->assertJsonPath('subscriptions.0.id', $subscription->id);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $context['company']->id,
            'action' => 'billing_cancellation_scheduled']);
    }

    public function test_cancellation_rejects_provider_customer_mismatch_without_mutating_subscription(): void
    {
        $context = $this->stage3AccountingContext(['billing.manage']);
        $plan = Plan::factory()->create(['code' => 'starter']);
        $subscription = Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_badCustomer',
            'billing_currency' => 'PKR', 'renews_at' => now()->addMonth(),
        ]);
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $context['company']->id,
            'provider' => 'stripe', 'provider_customer_id' => 'cus_correct',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->mock(BillingGateway::class)->shouldReceive('cancelAtPeriodEnd')->once()->andReturn([
            'id' => 'sub_badCustomer', 'customer' => 'cus_otherCompany', 'status' => 'active',
            'cancel_at_period_end' => true, 'current_period_end' => now()->addMonth()->timestamp,
        ]);
        $this->postJson('/api/v1/platform/billing/cancel', [], ['X-Company-Id' => $context['company']->id])
            ->assertConflict()->assertJsonPath('error_code', 'BILLING_PROVIDER_MISMATCH');
        $this->assertNull($subscription->fresh()->cancel_at_period_end_at);
    }

    public function test_payment_method_portal_uses_only_a_company_customer_and_restricted_configuration(): void
    {
        config()->set('services.stripe.enabled', true);
        config()->set('services.stripe.secret', 'test-only-placeholder');
        config()->set('services.stripe.webhook_secret', 'test-only-placeholder');
        config()->set('services.stripe.return_origin', 'https://example.test');
        config()->set('services.stripe.payment_method_portal_configuration_id', 'bpc_testPaymentOnly');
        $context = $this->stage3AccountingContext(['billing.manage']);
        $plan = Plan::factory()->create(['code' => 'starter']);
        Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_portalTest',
            'renews_at' => now()->addMonth(),
        ]);
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $context['company']->id,
            'provider' => 'stripe', 'provider_customer_id' => 'cus_portalTest',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $gateway = $this->mock(BillingGateway::class);
        $gateway->shouldReceive('retrievePortalConfiguration')->once()->with('bpc_testPaymentOnly')->andReturn([
            'id' => 'bpc_testPaymentOnly', 'active' => true,
            'features' => ['payment_method_update' => ['enabled' => true],
                'subscription_update' => ['enabled' => false], 'subscription_cancel' => ['enabled' => false],
                'subscription_pause' => ['enabled' => false], 'customer_update' => ['enabled' => false]],
        ]);
        $gateway->shouldReceive('createPaymentMethodPortalSession')->once()
            ->with('cus_portalTest', 'bpc_testPaymentOnly', 'https://example.test/billing')
            ->andReturn(['id' => 'bps_testPortal', 'customer' => 'cus_portalTest',
                'url' => 'https://billing.stripe.com/p/session/test']);

        $this->postJson('/api/v1/platform/billing/payment-method-portal', [],
            ['X-Company-Id' => $context['company']->id])->assertOk()
            ->assertExactJson(['url' => 'https://billing.stripe.com/p/session/test']);
    }

    public function test_payment_method_portal_fails_closed_for_a_configuration_with_subscription_changes(): void
    {
        config()->set('services.stripe.enabled', true);
        config()->set('services.stripe.secret', 'test-only-placeholder');
        config()->set('services.stripe.webhook_secret', 'test-only-placeholder');
        config()->set('services.stripe.return_origin', 'https://example.test');
        config()->set('services.stripe.payment_method_portal_configuration_id', 'bpc_testUnsafe');
        $context = $this->stage3AccountingContext(['billing.manage']);
        $plan = Plan::factory()->create(['code' => 'starter']);
        Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_portalUnsafe',
            'renews_at' => now()->addMonth(),
        ]);
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $context['company']->id,
            'provider' => 'stripe', 'provider_customer_id' => 'cus_portalUnsafe',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $gateway = $this->mock(BillingGateway::class);
        $gateway->shouldReceive('retrievePortalConfiguration')->once()->andReturn([
            'id' => 'bpc_testUnsafe', 'active' => true,
            'features' => ['payment_method_update' => ['enabled' => true],
                'subscription_update' => ['enabled' => true]],
        ]);
        $gateway->shouldNotReceive('createPaymentMethodPortalSession');

        $this->postJson('/api/v1/platform/billing/payment-method-portal', [],
            ['X-Company-Id' => $context['company']->id])->assertStatus(503)
            ->assertJsonPath('error_code', 'BILLING_PAYMENT_METHOD_UNAVAILABLE');
    }

    public function test_managed_company_cannot_use_legacy_manual_subscription_or_entitlement_mutations(): void
    {
        $context = $this->stage3AccountingContext(['platform.subscription.manage']);
        $context['company']->update(['billing_policy_applies' => true]);
        $plan = Plan::factory()->create(['code' => 'starter']);
        $subscription = Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_legacyGuard',
            'renews_at' => now()->addMonth(),
        ]);
        $headers = ['X-Company-Id' => $context['company']->id];

        $this->putJson('/api/v1/platform/subscription', [
            'plan_id' => $plan->id, 'status' => 'TRIALING', 'billing_interval' => 'monthly',
            'trial_ends_at' => now()->addDays(14)->toDateString(),
        ], $headers)->assertConflict()->assertJsonPath('error_code', 'BILLING_PROVIDER_AUTHORITY_REQUIRED');
        $this->putJson('/api/v1/platform/entitlements/ai', [
            'is_enabled' => true,
        ], $headers)->assertConflict()->assertJsonPath('error_code', 'BILLING_PROVIDER_AUTHORITY_REQUIRED');
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id,
            'company_id' => $context['company']->id, 'status' => 'ACTIVE']);
        $this->assertSame(1, Subscription::query()->where('company_id', $context['company']->id)->count());
        $this->assertDatabaseMissing('company_entitlements', ['company_id' => $context['company']->id,
            'module_key' => 'ai']);
    }

    public function test_managed_trial_employee_limit_applies_to_creation_and_reactivation(): void
    {
        $context = $this->stage3AccountingContext(['payroll.configure']);
        $context['company']->update(['billing_policy_applies' => true]);
        $plan = Plan::factory()->create(['code' => 'professional']);
        Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'TRIALING', 'trial_ends_at' => now()->addDays(7),
        ]);
        Employee::factory()->count(25)->for($context['company'])->create(['status' => 'active']);
        $former = Employee::factory()->for($context['company'])->create(['status' => 'resigned']);
        $headers = ['X-Company-Id' => $context['company']->id];

        $this->postJson('/api/v1/payroll/employees', [
            'employee_code' => 'LIMIT-26', 'full_name' => 'Over Limit', 'employment_type' => 'full_time',
            'status' => 'active', 'joining_date' => '2026-01-01',
        ], $headers)->assertConflict()->assertJsonPath('error_code', 'SUBSCRIPTION_LIMIT_REACHED');
        $this->putJson("/api/v1/payroll/employees/{$former->id}", ['status' => 'active'], $headers)
            ->assertConflict()->assertJsonPath('error_code', 'SUBSCRIPTION_LIMIT_REACHED');
        $this->assertSame('resigned', $former->fresh()->status);
        $this->assertDatabaseMissing('employees', ['company_id' => $context['company']->id,
            'employee_code' => 'LIMIT-26']);
    }

    public function test_billing_usage_is_server_counted_company_scoped_and_reports_the_approved_ai_allowance(): void
    {
        $context = $this->stage3AccountingContext(['billing.view']);
        $context['company']->update(['billing_policy_applies' => true]);
        $plan = Plan::factory()->create(['code' => 'professional']);
        Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'TRIALING', 'trial_ends_at' => now()->addDays(7),
        ]);
        CompanyInvitation::factory()->for($context['company'])->create();
        Employee::factory()->for($context['company'])->create(['status' => 'active']);
        Document::factory()->for($context['company'])->create(['size_bytes' => 1024]);
        AiUsageRecord::factory()->for($context['company'])->create(['input_tokens' => 120,
            'output_tokens' => 30]);
        $otherCompany = Company::factory()->create();
        AiUsageRecord::factory()->for($otherCompany)->create(['input_tokens' => 9000]);

        $this->getJson('/api/v1/platform/billing/usage', ['X-Company-Id' => $context['company']->id])
            ->assertOk()->assertJsonPath('users.active', 1)->assertJsonPath('users.pending_invitations', 1)
            ->assertJsonPath('users.included', 10)->assertJsonPath('employees.active', 1)
            ->assertJsonPath('storage.used_bytes', 1024)->assertJsonPath('ai.used_tokens', 150)
            ->assertJsonPath('ai.reserved_tokens', 0)->assertJsonPath('ai.remaining_tokens', 49_850)
            ->assertJsonPath('ai.included_tokens', 50_000)->assertJsonPath('ai.paid_overage_available', false);
    }

    public function test_managed_ai_rejects_an_unreserved_provider_request_before_it_can_cross_the_allowance(): void
    {
        $context = $this->stage3AccountingContext([]);
        $context['company']->update(['billing_policy_applies' => true]);
        $plan = Plan::factory()->create(['code' => 'enterprise']);
        Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'TRIALING', 'trial_ends_at' => now()->addDays(7),
        ]);
        AiUsageRecord::factory()->for($context['company'])->create([
            'input_tokens' => 49_900, 'output_tokens' => 0,
        ]);
        $meter = app(DatabaseAiUsageMeter::class);
        $meter->assertWithinLimit($context['company']->id, 'CHAT');
        try {
            $meter->reserveEmbedding($context['company']->id, 'RETRIEVAL', ['small input']);
            $this->fail('The provider request must be rejected before execution when its maximum usage cannot fit.');
        } catch (PlatformException $exception) {
            $this->assertSame('SUBSCRIPTION_LIMIT_REACHED', $exception->errorCode);
        }
        $this->assertSame(1, AiUsageRecord::query()->where('company_id', $context['company']->id)->count());
        $this->assertDatabaseCount('ai_usage_reservations', 0);
    }

    public function test_managed_ai_reservation_blocks_competing_requests_and_releases_unused_capacity(): void
    {
        config()->set('services.billing.ai_monthly_token_allowance', 3000);
        $context = $this->stage3AccountingContext([]);
        $context['company']->update(['billing_policy_applies' => true]);
        $plan = Plan::factory()->create(['code' => 'enterprise']);
        Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'TRIALING', 'trial_ends_at' => now()->addDays(7),
        ]);
        $meter = app(DatabaseAiUsageMeter::class);
        $first = $meter->reserveEmbedding($context['company']->id, 'RETRIEVAL', ['first']);
        $this->assertNotNull($first);
        try {
            $meter->reserveEmbedding($context['company']->id, 'RETRIEVAL', ['second']);
            $this->fail('In-flight usage must reduce available capacity.');
        } catch (PlatformException $exception) {
            $this->assertSame('SUBSCRIPTION_LIMIT_REACHED', $exception->errorCode);
        }

        $record = $meter->record($context['company']->id, $context['user']->id, [
            'provider' => 'fake', 'model' => 'fake', 'operation' => 'RETRIEVAL',
            'input_tokens' => 100, 'output_tokens' => 0, 'cost_minor' => 0,
        ], $first);
        $again = $meter->record($context['company']->id, $context['user']->id, [
            'provider' => 'fake', 'model' => 'fake', 'operation' => 'RETRIEVAL',
            'input_tokens' => 100, 'output_tokens' => 0, 'cost_minor' => 0,
        ], $first);
        $this->assertSame($record->id, $again->id);
        $this->assertDatabaseCount('ai_usage_records', 1);
        $this->assertDatabaseHas('ai_usage_reservations', ['id' => $first, 'status' => 'SETTLED',
            'actual_tokens' => 100]);
        try {
            $meter->record($context['company']->id, $context['user']->id, [
                'provider' => 'fake', 'model' => 'fake', 'operation' => 'RETRIEVAL',
                'input_tokens' => 101, 'output_tokens' => 0, 'cost_minor' => 0,
            ], $first);
            $this->fail('A settled reservation cannot be replayed with changed usage.');
        } catch (PlatformException $exception) {
            $this->assertSame('AI_USAGE_CONFLICT', $exception->errorCode);
        }

        $second = $meter->reserveEmbedding($context['company']->id, 'RETRIEVAL', ['second']);
        $this->assertNotNull($second);
        $meter->release($second);
        $this->assertDatabaseHas('ai_usage_reservations', ['id' => $second, 'status' => 'RELEASED']);
    }

    public function test_managed_ai_reservation_is_company_scoped_and_resets_at_the_next_month(): void
    {
        config()->set('services.billing.ai_monthly_token_allowance', 3000);
        $context = $this->stage3AccountingContext([]);
        $context['company']->update(['billing_policy_applies' => true]);
        $plan = Plan::factory()->create(['code' => 'enterprise']);
        Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'TRIALING', 'trial_ends_at' => now()->addMonths(3),
        ]);
        $meter = app(DatabaseAiUsageMeter::class);
        $reservationId = $meter->reserveEmbedding($context['company']->id, 'RETRIEVAL', ['company A']);
        $otherCompany = Company::factory()->create(['billing_policy_applies' => true]);
        try {
            $meter->record($otherCompany->id, $context['user']->id, [
                'provider' => 'fake', 'model' => 'fake', 'operation' => 'RETRIEVAL',
                'input_tokens' => 1, 'output_tokens' => 0, 'cost_minor' => 0,
            ], $reservationId);
            $this->fail('Another company cannot settle this reservation.');
        } catch (PlatformException $exception) {
            $this->assertSame('AI_USAGE_RESERVATION_INVALID', $exception->errorCode);
        }
        $this->assertDatabaseCount('ai_usage_records', 0);
        $meter->release($reservationId);
        AiUsageRecord::factory()->for($context['company'])->create([
            'input_tokens' => 2800, 'output_tokens' => 0, 'occurred_at' => now(),
        ]);
        try {
            $meter->reserveEmbedding($context['company']->id, 'RETRIEVAL', ['same month']);
            $this->fail('The current month allowance is insufficient.');
        } catch (PlatformException $exception) {
            $this->assertSame('SUBSCRIPTION_LIMIT_REACHED', $exception->errorCode);
        }

        $this->travelTo(now()->addMonth()->startOfMonth()->addDay());
        $nextMonth = $meter->reserveEmbedding($context['company']->id, 'RETRIEVAL', ['new month']);
        $this->assertNotNull($nextMonth);
        $this->assertDatabaseHas('ai_usage_reservations', ['id' => $nextMonth,
            'period_start' => now()->startOfMonth()->toDateString(), 'status' => 'PENDING']);
        $meter->release($nextMonth);
    }

    public function test_managed_chat_rejects_before_provider_execution_when_token_budget_is_insufficient(): void
    {
        $context = $this->stage12AiContext();
        $context['company']->update(['billing_policy_applies' => true]);
        $plan = Plan::factory()->create(['code' => 'enterprise']);
        Subscription::factory()->for($context['company'])->for($plan)->create([
            'status' => 'TRIALING', 'trial_ends_at' => now()->addDays(7),
        ]);
        AiUsageRecord::factory()->for($context['company'])->create([
            'input_tokens' => 49_900, 'output_tokens' => 0, 'occurred_at' => now(),
        ]);
        $conversation = AiConversation::factory()->for($context['company'])->for($context['user'])->create();
        $provider = $this->bindFakeAiProvider();

        $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['content' => 'Summarize cash.'],
            ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'quota-boundary'])
            ->assertConflict()->assertJsonPath('error_code', 'SUBSCRIPTION_LIMIT_REACHED');

        $this->assertSame([], $provider->requests);
        $this->assertDatabaseCount('ai_usage_reservations', 0);
    }
}
