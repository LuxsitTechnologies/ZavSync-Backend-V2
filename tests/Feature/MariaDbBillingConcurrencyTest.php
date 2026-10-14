<?php

namespace Tests\Feature;

use App\Models\BillingCataloguePrice;
use App\Models\BillingCheckoutAttempt;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Platform\BillingComponentService;
use Database\Seeders\BillingCatalogueSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CoordinatesDatabaseWorkers;
use Tests\Concerns\ResetsCommittedFixtures;
use Tests\MariaDbCertification;
use Tests\TestCase;

class MariaDbBillingConcurrencyTest extends TestCase
{
    use CoordinatesDatabaseWorkers, ResetsCommittedFixtures;

    private bool $databaseWasReset = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('ZAVSYNC_MARIADB_CERTIFICATION') || getenv('MARIADB_CERTIFICATION_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Requires guarded manual MariaDB concurrency mode.');
        }
        MariaDbCertification::guard($this->app);
        $this->databaseWasReset = true;
        $this->artisan('migrate:fresh', ['--database' => 'mysql', '--no-interaction' => true])->assertSuccessful();
        $this->seed(BillingCatalogueSeeder::class);
        config()->set('services.stripe.enabled', true);
        config()->set('services.stripe.secret', 'fake-test-token');
        config()->set('services.stripe.webhook_secret', 'fake-test-secret');
    }

    protected function tearDown(): void
    {
        $this->stopDatabaseWorkers();
        try {
            if ($this->databaseWasReset) {
                $this->resetCommittedFixtures('mysql');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_competing_component_confirmations_activate_exactly_once(): void
    {
        $company = Company::factory()->create(['billing_policy_applies' => true]);
        $plan = Plan::factory()->create(['code' => 'starter']);
        $base = Subscription::factory()->for($company)->for($plan)->create([
            'status' => 'ACTIVE', 'provider' => 'stripe', 'provider_reference' => 'sub_billingBaseRace',
            'billing_currency' => 'PKR', 'billing_interval' => 'monthly',
            'renews_at' => now()->addMonth(),
        ]);
        $price = BillingCataloguePrice::query()->where('kind', 'addon')->where('code', 'fbr')
            ->where('currency', 'PKR')->where('billing_interval', 'monthly')->firstOrFail();
        $price->update(['provider_price_id' => 'price_billingRace']);
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id,
            'provider' => 'stripe', 'provider_customer_id' => 'cus_billingRace',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $line = ['catalogue_price_id' => $price->id, 'kind' => 'addon', 'code' => 'fbr',
            'currency' => 'PKR', 'billing_interval' => 'monthly', 'unit_amount_minor' => 250_000,
            'quantity' => 1, 'total_minor' => 250_000];
        $attempt = BillingCheckoutAttempt::factory()->for($company)->create([
            'operation' => 'COMPONENT_CHECKOUT', 'status' => 'PAYMENT_PENDING',
            'currency' => 'PKR', 'subtotal_minor' => 250_000, 'total_minor' => 250_000,
            'provider_session_id' => 'cs_billingRace',
            'price_snapshot' => ['base_subscription_id' => $base->id, 'line' => $line,
                'provider_price_ids' => ['price_billingRace']],
        ]);
        $periodEnd = now()->addMonth()->timestamp;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/checkout/sessions/cs_billingRace' => Http::response([
                'id' => 'cs_billingRace', 'client_reference_id' => $attempt->id,
                'currency' => 'pkr', 'amount_subtotal' => 250_000, 'status' => 'complete',
                'payment_status' => 'paid', 'customer' => 'cus_billingRace',
                'subscription' => 'sub_billingComponentRace',
            ]),
            'https://api.stripe.com/v1/subscriptions/sub_billingComponentRace' => Http::response([
                'id' => 'sub_billingComponentRace', 'customer' => 'cus_billingRace', 'status' => 'active',
                'current_period_end' => $periodEnd,
                'items' => ['data' => [['quantity' => 1,
                    'price' => ['id' => 'price_billingRace', 'currency' => 'pkr']]]],
            ]),
        ]);

        $first = $this->startDatabaseWorker(function ($channel) use ($company, $attempt): array {
            return DB::transaction(function () use ($channel, $company, $attempt): array {
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');

                return app(BillingComponentService::class)->reconcile($company->id, $attempt->id);
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $attempt): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);

            return app(BillingComponentService::class)->reconcile($company->id, $attempt->id);
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');

        $winner = $this->finishWorker($first);
        $replay = $this->finishWorker($second);
        $this->assertSame('ACTIVE', $winner['status']);
        $this->assertSame($winner['subscription_id'], $replay['subscription_id']);
        $this->assertDatabaseCount('billing_component_subscriptions', 1);
        $this->assertDatabaseCount('billing_subscription_items', 1);
        $this->assertDatabaseHas('billing_checkout_attempts', ['id' => $attempt->id, 'status' => 'ACTIVE']);
        foreach (['invoices', 'journals', 'journal_lines', 'customer_payments', 'inventory_movements', 'bank_reconciliations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
