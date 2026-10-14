<?php

namespace Tests\Feature;

use App\Contracts\BillingGateway;
use App\Exceptions\PlatformException;
use App\Models\BillingCataloguePrice;
use App\Models\BillingCheckoutAttempt;
use App\Models\BillingComponentSubscription;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Platform\EntitlementService;
use Carbon\CarbonImmutable;
use Database\Seeders\BillingCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingPlanChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BillingCatalogueSeeder::class);
        config()->set('services.stripe.enabled', true);
        config()->set('services.stripe.secret', 'test-only-placeholder');
        config()->set('services.stripe.webhook_secret', 'test-only-placeholder');
    }

    public function test_upgrade_preserves_old_plan_until_full_new_plan_invoice_is_paid(): void
    {
        $context = $this->stage3AccountingContext(['billing.view', 'billing.manage']);
        $company = $context['company'];
        $company->update(['billing_policy_applies' => true]);
        CompanySetting::factory()->for($company)->create([
            'country_code' => 'PK', 'billing_country_confirmed_at' => now(),
        ]);
        $starter = Plan::factory()->create(['code' => 'starter']);
        Plan::factory()->create(['code' => 'professional']);
        $oldEnd = now()->addMonth()->startOfSecond()->timestamp;
        $newStart = now()->startOfSecond()->timestamp;
        $newEnd = now()->addMonth()->startOfSecond()->timestamp;
        $base = Subscription::factory()->for($company)->for($starter)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_upgradeBase',
            'billing_interval' => 'monthly', 'billing_currency' => 'PKR',
            'renews_at' => CarbonImmutable::createFromTimestampUTC($oldEnd),
        ]);
        $source = BillingCataloguePrice::query()->where('kind', 'plan')->where('code', 'starter')
            ->where('currency', 'PKR')->where('billing_interval', 'monthly')->firstOrFail();
        $source->update(['provider_price_id' => 'price_starterUpgrade']);
        $target = BillingCataloguePrice::query()->where('kind', 'plan')->where('code', 'professional')
            ->where('currency', 'PKR')->where('billing_interval', 'monthly')->firstOrFail();
        $target->update(['provider_price_id' => 'price_professionalUpgrade']);
        DB::table('billing_subscription_items')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'subscription_id' => $base->id,
            'catalogue_price_id' => $source->id, 'kind' => 'plan', 'code' => 'starter', 'currency' => 'PKR',
            'billing_interval' => 'monthly', 'unit_amount_minor' => 299_900, 'quantity' => 1,
            'total_minor' => 299_900, 'effective_at' => now()->subMonth(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id,
            'provider' => 'stripe', 'provider_customer_id' => 'cus_upgradeBase',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $addonPrice = BillingCataloguePrice::query()->where('kind', 'addon')->where('code', 'fbr')
            ->where('currency', 'PKR')->where('billing_interval', 'monthly')->firstOrFail();
        $addonPrice->update(['provider_price_id' => 'price_addonRetained']);
        $addonAttempt = BillingCheckoutAttempt::factory()->for($company)->create([
            'operation' => 'COMPONENT_CHECKOUT', 'status' => 'ACTIVE',
        ]);
        $addon = BillingComponentSubscription::factory()->for($company)->create([
            'subscription_id' => $base->id, 'catalogue_price_id' => $addonPrice->id,
            'checkout_attempt_id' => $addonAttempt->id, 'kind' => 'addon', 'code' => 'fbr',
            'provider_subscription_id' => 'sub_addonRetained',
            'paid_through_at' => CarbonImmutable::createFromTimestampUTC($oldEnd),
        ]);
        DB::table('billing_subscription_items')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'subscription_id' => $base->id,
            'component_subscription_id' => $addon->id, 'catalogue_price_id' => $addonPrice->id,
            'kind' => 'addon', 'code' => 'fbr', 'currency' => 'PKR', 'billing_interval' => 'monthly',
            'unit_amount_minor' => 250_000, 'quantity' => 1, 'total_minor' => 250_000,
            'effective_at' => now(), 'ends_at' => CarbonImmutable::createFromTimestampUTC($oldEnd),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $paid = false;
        $subscriptionReads = 0;
        $gateway = $this->mock(BillingGateway::class);
        $gateway->shouldReceive('retrievePrice')->once()->andReturn([
            'id' => 'price_professionalUpgrade', 'active' => true, 'currency' => 'pkr',
            'unit_amount' => 899_900, 'recurring' => ['interval' => 'month'],
        ]);
        $gateway->shouldReceive('retrieveSubscription')->times(3)->andReturnUsing(function () use (&$subscriptionReads, $oldEnd, $newStart, $newEnd): array {
            $subscriptionReads++;
            $targetVisible = $subscriptionReads > 1;

            return ['id' => 'sub_upgradeBase', 'customer' => 'cus_upgradeBase', 'status' => 'active',
                'current_period_start' => $targetVisible ? $newStart : $newStart - 2_592_000,
                'current_period_end' => $targetVisible ? $newEnd : $oldEnd,
                'latest_invoice' => 'in_upgradeInvoice',
                'pending_update' => null,
                'items' => ['data' => [['id' => 'si_upgradeBase', 'quantity' => 1,
                    'price' => ['id' => $targetVisible ? 'price_professionalUpgrade' : 'price_starterUpgrade',
                        'currency' => 'pkr']]]],
            ];
        });
        $gateway->shouldReceive('upgradePlan')->once()->andReturn([
            'id' => 'sub_upgradeBase', 'customer' => 'cus_upgradeBase',
            'latest_invoice' => 'in_upgradeInvoice',
        ]);
        $gateway->shouldReceive('cancelAtPeriodEnd')->once()->with('sub_addonRetained', \Mockery::type('string'))
            ->andReturn(['id' => 'sub_addonRetained', 'cancel_at_period_end' => true,
                'customer' => 'cus_upgradeBase', 'status' => 'active',
                'current_period_end' => $oldEnd]);
        $gateway->shouldReceive('retrieveInvoice')->twice()->andReturnUsing(function () use (&$paid, $newEnd): array {
            return ['id' => 'in_upgradeInvoice', 'subscription' => 'sub_upgradeBase',
                'customer' => 'cus_upgradeBase', 'currency' => 'pkr',
                'status' => $paid ? 'paid' : 'open', 'subtotal' => 899_900,
                'total' => 899_900, 'amount_paid' => $paid ? 899_900 : 0,
                'lines' => ['data' => [['price' => ['id' => 'price_professionalUpgrade'],
                    'period' => ['end' => $newEnd]]]],
            ];
        });
        $headers = ['X-Company-Id' => $company->id, 'Idempotency-Key' => 'Upgrade-Pro-1'];
        $changeId = $this->postJson('/api/v1/platform/billing/plan-change', [
            'plan' => 'professional', 'billing_interval' => 'monthly',
        ], $headers)->assertOk()->assertJsonPath('status', 'PAYMENT_PENDING')->json('change_id');
        $this->assertSame('starter', $base->fresh()->plan->code);
        $this->postJson("/api/v1/platform/billing/plan-change/{$changeId}/reconcile", [], $headers)
            ->assertOk()->assertJsonPath('status', 'PAYMENT_PENDING');
        $this->assertSame('starter', $base->fresh()->plan->code);
        $paid = true;
        $this->postJson("/api/v1/platform/billing/plan-change/{$changeId}/reconcile", [], $headers)
            ->assertOk()->assertJsonPath('status', 'APPLIED');
        $this->assertSame('professional', $base->fresh()->plan->code);
        $this->assertSame($oldEnd, $addon->fresh()->paid_through_at->timestamp);
        $this->assertNotNull($addon->fresh()->cancel_at_period_end_at);
        $this->assertDatabaseHas('billing_subscription_items', [
            'subscription_id' => $base->id, 'kind' => 'plan', 'code' => 'professional',
        ]);
        $this->assertDatabaseCount('billing_documents', 1);
        $this->assertDatabaseCount('billing_plan_changes', 1);
        $this->postJson('/api/v1/platform/billing/plan-change', [
            'plan' => 'professional', 'billing_interval' => 'monthly',
        ], $headers)->assertOk()->assertJsonPath('change_id', $changeId)
            ->assertJsonPath('status', 'APPLIED');
        $this->postJson('/api/v1/platform/billing/plan-change', [
            'plan' => 'enterprise', 'billing_interval' => 'monthly',
        ], $headers)->assertConflict()->assertJsonPath('error_code', 'BILLING_IDEMPOTENCY_CONFLICT');
        foreach (['invoices', 'journals', 'journal_lines', 'customer_payments', 'inventory_movements', 'bank_reconciliations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_downgrade_is_scheduled_at_paid_period_end_and_does_not_delete_over_limit_data(): void
    {
        $context = $this->stage3AccountingContext(['billing.view', 'billing.manage']);
        $company = $context['company'];
        $company->update(['billing_policy_applies' => true]);
        CompanySetting::factory()->for($company)->create([
            'country_code' => 'PK', 'billing_country_confirmed_at' => now(),
        ]);
        $professional = Plan::factory()->create(['code' => 'professional']);
        Plan::factory()->create(['code' => 'starter']);
        $start = now()->subMonth()->startOfSecond()->timestamp;
        $boundary = now()->addMonth()->startOfSecond()->timestamp;
        $nextEnd = now()->addMonths(2)->startOfSecond()->timestamp;
        $base = Subscription::factory()->for($company)->for($professional)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_downgradeBase',
            'billing_interval' => 'monthly', 'billing_currency' => 'PKR',
            'renews_at' => CarbonImmutable::createFromTimestampUTC($boundary),
        ]);
        $source = BillingCataloguePrice::query()->where('kind', 'plan')->where('code', 'professional')
            ->where('currency', 'PKR')->where('billing_interval', 'monthly')->firstOrFail();
        $source->update(['provider_price_id' => 'price_proDowngrade']);
        $target = BillingCataloguePrice::query()->where('kind', 'plan')->where('code', 'starter')
            ->where('currency', 'PKR')->where('billing_interval', 'monthly')->firstOrFail();
        $target->update(['provider_price_id' => 'price_starterDowngrade']);
        DB::table('billing_subscription_items')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'subscription_id' => $base->id,
            'catalogue_price_id' => $source->id, 'kind' => 'plan', 'code' => 'professional',
            'currency' => 'PKR', 'billing_interval' => 'monthly', 'unit_amount_minor' => 899_900,
            'quantity' => 1, 'total_minor' => 899_900, 'effective_at' => now()->subMonth(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id,
            'provider' => 'stripe', 'provider_customer_id' => 'cus_downgradeBase',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $newPhase = false;
        $gateway = $this->mock(BillingGateway::class);
        $gateway->shouldReceive('retrievePrice')->once()->andReturn([
            'id' => 'price_starterDowngrade', 'active' => true, 'currency' => 'pkr',
            'unit_amount' => 299_900, 'recurring' => ['interval' => 'month'],
        ]);
        $gateway->shouldReceive('retrieveSubscription')->times(3)->andReturnUsing(function () use (&$newPhase, $start, $boundary, $nextEnd): array {
            return ['id' => 'sub_downgradeBase', 'customer' => 'cus_downgradeBase', 'status' => 'active',
                'current_period_start' => $newPhase ? $boundary : $start,
                'current_period_end' => $newPhase ? $nextEnd : $boundary,
                'latest_invoice' => 'in_downgradePaid', 'schedule' => null,
                'items' => ['data' => [['id' => 'si_downgradeBase', 'quantity' => 1,
                    'price' => ['id' => $newPhase ? 'price_starterDowngrade' : 'price_proDowngrade',
                        'currency' => 'pkr']]]],
            ];
        });
        $gateway->shouldReceive('createScheduleFromSubscription')->once()->andReturn([
            'id' => 'sub_sched_downgradeBase', 'subscription' => 'sub_downgradeBase',
        ]);
        $gateway->shouldReceive('schedulePlanChange')->once()->andReturn([
            'id' => 'sub_sched_downgradeBase', 'subscription' => 'sub_downgradeBase',
            'phases' => [[], ['start_date' => $boundary,
                'items' => [['price' => 'price_starterDowngrade']]]],
        ]);
        $gateway->shouldReceive('retrieveInvoice')->once()->andReturn([
            'id' => 'in_downgradePaid', 'subscription' => 'sub_downgradeBase',
            'customer' => 'cus_downgradeBase', 'currency' => 'pkr', 'status' => 'paid',
            'subtotal' => 299_900, 'total' => 299_900, 'amount_paid' => 299_900,
            'lines' => ['data' => [['price' => ['id' => 'price_starterDowngrade'],
                'period' => ['end' => $nextEnd]]]],
        ]);
        $headers = ['X-Company-Id' => $company->id, 'Idempotency-Key' => 'Downgrade-Starter-1'];
        $changeId = $this->postJson('/api/v1/platform/billing/plan-change', [
            'plan' => 'starter', 'billing_interval' => 'monthly',
        ], $headers)->assertOk()->assertJsonPath('status', 'SCHEDULED')->json('change_id');
        $this->assertSame('professional', $base->fresh()->plan->code);
        CompanyUser::factory()->count(4)->create(['company_id' => $company->id]);
        $this->postJson("/api/v1/platform/billing/plan-change/{$changeId}/reconcile", [], $headers)
            ->assertOk()->assertJsonPath('status', 'SCHEDULED');
        $newPhase = true;
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($boundary + 1));
        $this->postJson("/api/v1/platform/billing/plan-change/{$changeId}/reconcile", [], $headers)
            ->assertOk()->assertJsonPath('status', 'APPLIED');
        $this->assertSame('starter', $base->fresh()->plan->code);
        $this->getJson('/api/v1/platform/billing/usage', $headers)
            ->assertOk()->assertJsonPath('users.over_limit', true)
            ->assertJsonPath('users.capacity', 3);
        $this->assertSame(5, CompanyUser::query()->where('company_id', $company->id)->where('is_active', true)->count());
        $this->assertDatabaseHas('billing_subscription_items', [
            'subscription_id' => $base->id, 'kind' => 'plan', 'code' => 'starter',
        ]);
        try {
            app(EntitlementService::class)->assertWithinLimit($company->id, 'users', 4, 1);
            $this->fail('A downgrade must block additional capacity growth.');
        } catch (PlatformException $exception) {
            $this->assertSame('SUBSCRIPTION_LIMIT_REACHED', $exception->errorCode);
        }
    }
}
