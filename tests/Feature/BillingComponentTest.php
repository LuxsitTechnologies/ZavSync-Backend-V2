<?php

namespace Tests\Feature;

use App\Contracts\BillingGateway;
use App\Models\BillingCataloguePrice;
use App\Models\BillingCheckoutAttempt;
use App\Models\BillingComponentSubscription;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Platform\BillingComponentService;
use App\Services\Platform\EntitlementService;
use Database\Seeders\BillingCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingComponentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BillingCatalogueSeeder::class);
        config()->set('services.stripe.enabled', true);
        config()->set('services.stripe.secret', 'test-only-placeholder');
        config()->set('services.stripe.webhook_secret', 'test-only-placeholder');
        config()->set('services.stripe.return_origin', 'https://example.test');
    }

    public function test_addon_has_an_independent_paid_term_and_is_not_active_before_payment(): void
    {
        $context = $this->stage3AccountingContext(['billing.view', 'billing.manage']);
        $company = $context['company'];
        $company->update(['billing_policy_applies' => true]);
        CompanySetting::factory()->for($company)->create([
            'country_code' => 'PK', 'billing_country_confirmed_at' => now(), 'email' => 'billing@example.test',
        ]);
        $plan = Plan::factory()->create(['code' => 'starter']);
        $base = Subscription::factory()->for($company)->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_baseComponent',
            'billing_interval' => 'monthly', 'billing_currency' => 'PKR', 'renews_at' => now()->addMonth(),
        ]);
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'provider' => 'stripe',
            'provider_customer_id' => 'cus_componentCompany', 'created_at' => now(), 'updated_at' => now(),
        ]);
        BillingCataloguePrice::query()->where('kind', 'addon')->where('code', 'fbr')->where('currency', 'PKR')
            ->where('billing_interval', 'monthly')->update(['provider_price_id' => 'price_componentFbr']);
        $periodEnd = now()->addMonth()->timestamp;
        $gateway = $this->mock(BillingGateway::class);
        $gateway->shouldReceive('retrievePrice')->once()->andReturn([
            'id' => 'price_componentFbr', 'active' => true, 'currency' => 'pkr',
            'unit_amount' => 250_000, 'recurring' => ['interval' => 'month'],
        ]);
        $gateway->shouldReceive('createComponentCheckout')->once()->andReturn([
            'id' => 'cs_componentFbr', 'url' => 'https://checkout.stripe.com/component-fbr',
        ]);
        $gateway->shouldReceive('retrieveCheckout')->once()->andReturnUsing(function (string $sessionId): array {
            return ['id' => $sessionId, 'client_reference_id' => BillingCheckoutAttempt::query()
                ->where('provider_session_id', $sessionId)->value('id'),
                'currency' => 'pkr', 'amount_subtotal' => 250_000, 'status' => 'complete',
                'payment_status' => 'paid', 'customer' => 'cus_componentCompany',
                'subscription' => 'sub_componentFbr'];
        });
        $gateway->shouldReceive('retrieveSubscription')->once()->andReturn([
            'id' => 'sub_componentFbr', 'customer' => 'cus_componentCompany', 'status' => 'active',
            'current_period_end' => $periodEnd,
            'items' => ['data' => [['quantity' => 1,
                'price' => ['id' => 'price_componentFbr', 'currency' => 'pkr']]]],
        ]);
        $gateway->shouldReceive('cancelAtPeriodEnd')->once()->andReturn([
            'id' => 'sub_componentFbr', 'cancel_at_period_end' => true,
            'customer' => 'cus_componentCompany', 'status' => 'active',
            'current_period_end' => $periodEnd,
        ]);
        $headers = ['X-Company-Id' => $company->id, 'Idempotency-Key' => 'Addon-FBR-1'];
        $this->assertNotContains('fbr', app(EntitlementService::class)->enabledModules($company->id));
        $attemptId = $this->postJson('/api/v1/platform/billing/components/checkout', [
            'kind' => 'addon', 'code' => 'fbr', 'quantity' => 1,
        ], $headers)->assertOk()->assertJsonPath('status', 'PAYMENT_PENDING')->json('attempt_id');
        $this->assertNotContains('fbr', app(EntitlementService::class)->enabledModules($company->id));
        $componentId = $this->postJson("/api/v1/platform/billing/checkout/{$attemptId}/reconcile", [], $headers)
            ->assertOk()->assertJsonPath('status', 'ACTIVE')->json('subscription_id');
        $this->assertContains('fbr', app(EntitlementService::class)->enabledModules($company->id));
        $this->assertDatabaseHas('billing_component_subscriptions', [
            'id' => $componentId, 'company_id' => $company->id, 'subscription_id' => $base->id,
            'provider_subscription_id' => 'sub_componentFbr', 'quantity' => 1,
        ]);
        $this->postJson("/api/v1/platform/billing/checkout/{$attemptId}/reconcile", [], $headers)
            ->assertOk()->assertJsonPath('subscription_id', $componentId);
        $this->postJson('/api/v1/platform/billing/components/checkout', [
            'kind' => 'addon', 'code' => 'fbr', 'quantity' => 1,
        ], [...$headers, 'Idempotency-Key' => 'Addon-FBR-2'])
            ->assertConflict()->assertJsonPath('error_code', 'BILLING_COMPONENT_ALREADY_ACTIVE');
        $this->postJson("/api/v1/platform/billing/components/{$componentId}/cancel", [], $headers)
            ->assertOk()->assertJsonPath('component_id', $componentId);
        $this->postJson("/api/v1/platform/billing/components/{$componentId}/cancel", [], $headers)
            ->assertOk()->assertJsonPath('component_id', $componentId);
        $this->assertContains('fbr', app(EntitlementService::class)->enabledModules($company->id));
        $this->assertDatabaseCount('billing_subscription_items', 1);
        foreach (['invoices', 'journals', 'journal_lines', 'customer_payments', 'inventory_movements', 'bank_reconciliations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_stale_failed_payment_does_not_erase_paid_term_and_verified_renewal_extends_it_once(): void
    {
        $company = Company::factory()->create();
        $plan = Plan::factory()->create(['code' => 'professional']);
        $base = Subscription::factory()->for($company)->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_baseRenewal',
            'billing_interval' => 'annual', 'billing_currency' => 'PKR', 'renews_at' => now()->addYear(),
        ]);
        $price = BillingCataloguePrice::query()->where('kind', 'seat')->where('code', 'user_overage')
            ->where('currency', 'PKR')->where('billing_interval', 'monthly')->firstOrFail();
        $price->update(['provider_price_id' => 'price_renewingSeat']);
        $attempt = BillingCheckoutAttempt::factory()->for($company)->create([
            'operation' => 'COMPONENT_CHECKOUT', 'status' => 'ACTIVE',
        ]);
        $oldEnd = now()->addMonth()->startOfSecond();
        $newEnd = $oldEnd->copy()->addMonth();
        $component = BillingComponentSubscription::factory()->for($company)->create([
            'subscription_id' => $base->id, 'catalogue_price_id' => $price->id,
            'checkout_attempt_id' => $attempt->id, 'kind' => 'seat', 'code' => 'user_overage',
            'quantity' => 2, 'provider_subscription_id' => 'sub_renewingSeat', 'paid_through_at' => $oldEnd,
        ]);
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'provider' => 'stripe',
            'provider_customer_id' => 'cus_renewalCompany', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('billing_subscription_items')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'subscription_id' => $base->id,
            'component_subscription_id' => $component->id, 'catalogue_price_id' => $price->id,
            'kind' => 'seat', 'code' => 'user_overage', 'currency' => 'PKR', 'billing_interval' => 'monthly',
            'unit_amount_minor' => 50_000, 'quantity' => 2, 'total_minor' => 100_000,
            'effective_at' => now(), 'ends_at' => $oldEnd, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $gateway = $this->mock(BillingGateway::class);
        $reduced = false;
        $gateway->shouldReceive('retrieveSubscription')->times(4)->andReturnUsing(function () use (&$reduced, $oldEnd, $newEnd): array {
            return ['id' => 'sub_renewingSeat', 'customer' => 'cus_renewalCompany', 'status' => 'active',
                'current_period_end' => $reduced ? $newEnd->timestamp : $oldEnd->timestamp,
                'items' => ['data' => [['id' => 'si_renewingSeat', 'quantity' => $reduced ? 1 : 2,
                    'price' => ['id' => 'price_renewingSeat', 'currency' => 'pkr']]]],
            ];
        });
        $gateway->shouldReceive('scheduleComponentQuantity')->once()->andReturnUsing(function () use (&$reduced, $oldEnd): array {
            $reduced = true;

            return ['id' => 'sub_renewingSeat', 'customer' => 'cus_renewalCompany', 'status' => 'active',
                'current_period_end' => $oldEnd->timestamp,
                'items' => ['data' => [['quantity' => 1,
                    'price' => ['id' => 'price_renewingSeat']]]],
            ];
        });
        $service = app(BillingComponentService::class);
        $service->reduceSeats($company->id, User::factory()->create(), $component->id, 1, 'seat-reduce-1');
        $this->assertSame(2, app(EntitlementService::class)->paidSeatQuantity($company->id, $base, 'users'));
        $this->assertDatabaseHas('billing_component_quantity_changes', ['company_id' => $company->id,
            'component_subscription_id' => $component->id, 'status' => 'SCHEDULED', 'target_quantity' => 1]);
        $failed = ['id' => 'in_oldFailure', 'customer' => 'cus_renewalCompany', 'currency' => 'pkr',
            'status' => 'open', 'subtotal' => 100_000, 'total' => 100_000];
        $service->applyProviderEvent($component, ['id' => 'evt_failed', 'type' => 'invoice.payment_failed'], $failed);
        $this->assertSame($oldEnd->timestamp, $component->fresh()->paid_through_at->timestamp);
        $this->assertSame(2, app(EntitlementService::class)->paidSeatQuantity($company->id, $base, 'users'));
        $paid = ['id' => 'in_renewed', 'customer' => 'cus_renewalCompany', 'currency' => 'pkr',
            'status' => 'paid', 'subtotal' => 50_000, 'total' => 50_000, 'amount_paid' => 50_000,
            'lines' => ['data' => [['period' => ['end' => $newEnd->timestamp],
                'quantity' => 1, 'price' => ['id' => 'price_renewingSeat']]]]];
        $service->applyProviderEvent($component, ['id' => 'evt_paid', 'type' => 'invoice.paid'], $paid);
        $service->applyProviderEvent($component, ['id' => 'evt_paidAgain', 'type' => 'invoice.paid'], $paid);
        $this->assertSame($newEnd->timestamp, $component->fresh()->paid_through_at->timestamp);
        $this->assertSame(1, app(EntitlementService::class)->paidSeatQuantity($company->id, $base, 'users'));
        $this->assertDatabaseCount('billing_documents', 2);
        $this->assertDatabaseCount('billing_subscription_items', 1);
        $this->assertDatabaseHas('billing_component_quantity_changes', ['company_id' => $company->id,
            'component_subscription_id' => $component->id, 'status' => 'APPLIED', 'target_quantity' => 1]);
        $this->assertSame('APPLIED', $service->reduceSeats($company->id, User::factory()->create(),
            $component->id, 1, 'seat-reduce-1')['status']);
    }

    public function test_component_and_plan_changes_require_billing_manage_and_company_membership(): void
    {
        $context = $this->stage3AccountingContext(['billing.view']);
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'Denied-1'];
        $this->postJson('/api/v1/platform/billing/components/checkout', [
            'kind' => 'addon', 'code' => 'fbr', 'quantity' => 1,
        ], $headers)->assertForbidden();
        $this->postJson('/api/v1/platform/billing/plan-change', [
            'plan' => 'professional', 'billing_interval' => 'monthly',
        ], $headers)->assertForbidden();

        $this->actingAsCompanyUser(['billing.manage']);
        $this->postJson('/api/v1/platform/billing/components/checkout', [
            'kind' => 'addon', 'code' => 'fbr', 'quantity' => 1,
        ], $headers)->assertForbidden()->assertJsonPath('error_code', 'TENANT_ACCESS_DENIED');
        $this->postJson('/api/v1/platform/billing/plan-change', [
            'plan' => 'professional', 'billing_interval' => 'monthly',
        ], $headers)->assertForbidden()->assertJsonPath('error_code', 'TENANT_ACCESS_DENIED');
        $this->assertDatabaseCount('billing_checkout_attempts', 0);
        $this->assertDatabaseCount('billing_plan_changes', 0);
    }
}
