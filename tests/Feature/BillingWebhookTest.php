<?php

namespace Tests\Feature;

use App\Contracts\BillingGateway;
use App\Models\BillingCheckoutAttempt;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Platform\BillingReconciliationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BillingWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_stripe_webhook_requires_valid_signature_and_deduplicates_event_ids(): void
    {
        config()->set('services.stripe.enabled', true);
        config()->set('services.stripe.webhook_secret', 'webhook-test-secret');
        $payload = json_encode([
            'id' => 'evt_local_test_1', 'type' => 'customer.updated', 'created' => now()->timestamp,
            'data' => ['object' => ['id' => 'cus_local_test_1']],
        ], JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'webhook-test-secret');
        $path = '/api/v1/billing/stripe/webhook';
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature];

        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)
            ->assertBadRequest()->assertJsonPath('error_code', 'BILLING_WEBHOOK_INVALID');
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.str_repeat('0', 64)], $payload)
            ->assertBadRequest()->assertJsonPath('error_code', 'BILLING_WEBHOOK_INVALID');
        $this->call('POST', $path, [], [], [], $server, $payload)
            ->assertOk()->assertJsonPath('duplicate', false);
        $this->call('POST', $path, [], [], [], $server, $payload)
            ->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('billing_provider_events', 1);
        $this->assertDatabaseHas('billing_provider_events', ['provider_event_id' => 'evt_local_test_1', 'processing_state' => 'IGNORED']);
    }

    public function test_expired_signature_and_disabled_provider_fail_closed(): void
    {
        config()->set('services.stripe.enabled', true);
        config()->set('services.stripe.webhook_secret', 'webhook-test-secret');
        $payload = json_encode(['id' => 'evt_local_test_2', 'type' => 'customer.updated',
            'created' => now()->timestamp, 'data' => ['object' => ['id' => 'cus_local_test_2']]], JSON_THROW_ON_ERROR);
        $timestamp = now()->subMinutes(6)->timestamp;
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'webhook-test-secret');
        $this->call('POST', '/api/v1/billing/stripe/webhook', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload)
            ->assertBadRequest();
        config()->set('services.stripe.enabled', false);
        $this->call('POST', '/api/v1/billing/stripe/webhook', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload)
            ->assertStatus(503)->assertJsonPath('error_code', 'BILLING_WEBHOOK_NOT_CONFIGURED');

        $this->assertDatabaseCount('billing_provider_events', 0);
    }

    public function test_reused_event_id_cannot_change_its_object_and_stale_claim_cannot_finalize(): void
    {
        config()->set('services.stripe.enabled', true);
        config()->set('services.stripe.webhook_secret', 'webhook-test-secret');
        $company = Company::factory()->create();
        BillingCheckoutAttempt::factory()->for($company)->create([
            'status' => 'PAYMENT_PENDING', 'provider_session_id' => 'cs_testStale',
        ]);
        $this->mock(BillingReconciliationService::class)->shouldReceive('reconcile')->once()
            ->andReturnUsing(function (): array {
                DB::table('billing_provider_events')->where('provider_event_id', 'evt_testStale')
                    ->update(['claim_generation' => 2, 'processing_state' => 'PROCESSING']);

                return ['attempt_id' => 'test', 'status' => 'ACTIVE', 'subscription_id' => 'test'];
            });
        $event = ['id' => 'evt_testStale', 'type' => 'checkout.session.completed', 'created' => now()->timestamp,
            'data' => ['object' => ['id' => 'cs_testStale']]];
        $this->sendSignedEvent($event)->assertOk();
        $this->assertDatabaseHas('billing_provider_events', ['provider_event_id' => 'evt_testStale',
            'claim_generation' => 2, 'processing_state' => 'PROCESSING']);
        $event['data']['object']['id'] = 'cs_different';
        $this->sendSignedEvent($event)->assertBadRequest()->assertJsonPath('error_code', 'BILLING_WEBHOOK_INVALID');
        $this->assertDatabaseCount('billing_provider_events', 1);
    }

    public function test_superseded_webhook_claim_cannot_apply_checkout_effects(): void
    {
        config()->set('services.stripe.enabled', true);
        config()->set('services.stripe.webhook_secret', 'webhook-test-secret');
        $company = Company::factory()->create();
        BillingCheckoutAttempt::factory()->for($company)->create([
            'status' => 'PAYMENT_PENDING', 'provider_session_id' => 'cs_testSuperseded',
        ]);
        $this->mock(BillingReconciliationService::class)->shouldNotReceive('reconcile');
        $superseded = false;
        DB::listen(function (QueryExecuted $query) use (&$superseded): void {
            if ($superseded || ! str_contains($query->sql, 'billing_provider_events')
                || ! str_contains($query->sql, 'claim_generation') || ! str_starts_with(strtolower($query->sql), 'update')) {
                return;
            }
            $superseded = true;
            DB::table('billing_provider_events')->where('provider_event_id', 'evt_testSuperseded')
                ->update(['claim_generation' => 2]);
        });

        $this->sendSignedEvent(['id' => 'evt_testSuperseded', 'type' => 'checkout.session.completed',
            'created' => now()->timestamp, 'data' => ['object' => ['id' => 'cs_testSuperseded']]])->assertOk();

        $this->assertTrue($superseded);
        $this->assertDatabaseHas('billing_provider_events', ['provider_event_id' => 'evt_testSuperseded',
            'claim_generation' => 2, 'processing_state' => 'PROCESSING']);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_signed_failed_payment_enters_seven_day_grace_and_later_paid_event_restores_active_state(): void
    {
        config()->set('services.stripe.enabled', true);
        config()->set('services.stripe.webhook_secret', 'webhook-test-secret');
        $context = $this->stage3AccountingContext(['billing.manage', 'billing.view']);
        $company = $context['company'];
        $plan = Plan::factory()->create();
        $subscription = Subscription::factory()->for($company)->for($plan)->create([
            'provider' => 'stripe', 'provider_reference' => 'sub_testRenewal', 'billing_currency' => 'PKR',
            'status' => 'ACTIVE', 'renews_at' => now()->addMonth(),
        ]);
        BillingCheckoutAttempt::factory()->for($company)->create([
            'status' => 'ACTIVE', 'provider_subscription_id' => 'sub_testRenewal',
            'price_snapshot' => ['provider_price_ids' => ['price_testPlan']],
        ]);
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id,
            'provider' => 'stripe', 'provider_customer_id' => 'cus_testRenewal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $gateway = $this->mock(BillingGateway::class);
        $gateway->shouldReceive('retrieveInvoice')->times(4)->andReturnUsing(function (string $invoiceId): array {
            $paid = $invoiceId === 'in_testPaid';

            return ['id' => $invoiceId, 'subscription' => 'sub_testRenewal', 'customer' => 'cus_testRenewal',
                'currency' => 'pkr', 'status' => $paid ? 'paid' : 'open', 'subtotal' => 299_900,
                'total' => 299_900, 'amount_paid' => $paid ? 299_900 : 0,
                'total_details' => ['amount_tax' => 0], 'created' => now()->timestamp,
                'hosted_invoice_url' => $paid ? 'https://invoice.stripe.com/i/test-safe' : null,
                'invoice_pdf' => $paid ? 'https://pay.stripe.com/invoice/test-safe/pdf' : null,
                'status_transitions' => ['paid_at' => $paid ? now()->timestamp : null],
                'lines' => ['data' => []]];
        });
        $gateway->shouldReceive('retrieveSubscription')->times(3)->andReturnUsing(function (): array {
            $paid = DB::table('billing_documents')->where('provider_document_id', 'in_testFailed')->exists();

            return ['id' => 'sub_testRenewal', 'customer' => 'cus_testRenewal',
                'status' => $paid ? 'active' : 'past_due', 'current_period_end' => now()->addMonth()->timestamp,
                'items' => ['data' => [['price' => ['id' => 'price_testPlan', 'currency' => 'pkr']]]]];
        });
        $failed = ['id' => 'evt_testFailed', 'type' => 'invoice.payment_failed', 'created' => now()->timestamp,
            'data' => ['object' => ['id' => 'in_testFailed']]];
        $this->sendSignedEvent($failed)->assertOk();
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id, 'status' => 'PAST_DUE_GRACE']);
        $this->assertNotNull($subscription->fresh()->grace_ends_at);
        $this->assertDatabaseCount('billing_notices', 1);
        $this->assertDatabaseHas('platform_notifications', ['company_id' => $company->id,
            'recipient_id' => $context['user']->id, 'type' => 'billing.payment_failed', 'channel' => 'IN_APP']);
        $this->assertDatabaseHas('platform_notifications', ['company_id' => $company->id,
            'recipient_id' => $context['user']->id, 'type' => 'billing.payment_failed', 'channel' => 'EMAIL']);
        $this->sendSignedEvent($failed)->assertOk()->assertJsonPath('duplicate', true);
        $paid = ['id' => 'evt_testPaid', 'type' => 'invoice.paid', 'created' => now()->timestamp,
            'data' => ['object' => ['id' => 'in_testPaid']]];
        $this->sendSignedEvent($paid)->assertOk();
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id, 'status' => 'ACTIVE', 'grace_ends_at' => null]);
        $this->assertDatabaseHas('billing_documents', ['company_id' => $company->id,
            'provider_document_id' => 'in_testPaid', 'provider_status' => 'paid', 'paid_minor' => 299_900]);
        $this->sendSignedEvent(['id' => 'evt_testLateFailure', 'type' => 'invoice.payment_failed',
            'created' => now()->subMinute()->timestamp, 'data' => ['object' => ['id' => 'in_testFailed']]])->assertOk();
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id, 'status' => 'ACTIVE', 'grace_ends_at' => null]);
        $this->assertDatabaseCount('billing_notices', 1);
        $documentId = DB::table('billing_documents')->where('provider_document_id', 'in_testPaid')->value('id');
        $this->getJson("/api/v1/platform/billing/documents/{$documentId}/access", ['X-Company-Id' => $company->id])
            ->assertOk()->assertJsonPath('pdf_url', 'https://pay.stripe.com/invoice/test-safe/pdf');
        $this->assertDatabaseCount('billing_provider_events', 3);
    }

    /** @param array<string, mixed> $event */
    private function sendSignedEvent(array $event): TestResponse
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'webhook-test-secret');

        return $this->call('POST', '/api/v1/billing/stripe/webhook', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload);
    }
}
