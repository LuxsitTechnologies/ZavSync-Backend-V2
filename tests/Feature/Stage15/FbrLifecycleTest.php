<?php

namespace Tests\Feature\Stage15;

use App\Contracts\FbrGateway;
use App\Enums\FbrSubmissionStatus;
use App\Models\FbrCompanyConfiguration;
use App\Models\FbrSubmissionAttempt;
use App\Models\PakistanFbrInvoice;
use App\Models\PakistanFbrInvoiceLine;
use App\Services\Fbr\FbrSubmissionContext;
use App\Services\Fbr\FbrSubmissionResult;
use App\Services\Fbr\PakistanFbrPayloadMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FbrLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_pakistan_payload_mapper_uses_company_seller_buyer_and_verified_line_fields(): void
    {
        $context = $this->stage3AccountingContext();
        $invoice = PakistanFbrInvoice::factory()->for($context['company'])->create(['created_by' => $context['user']->id]);
        PakistanFbrInvoiceLine::factory()->for($invoice, 'invoice')->create(['sro_schedule_id' => 'SRO-1', 'sro_item_id' => '1']);
        $configuration = FbrCompanyConfiguration::factory()->for($context['company'])->create(['seller_business_name' => 'Seller Limited', 'seller_province' => 'SINDH', 'updated_by' => $context['user']->id]);

        $payload = app(PakistanFbrPayloadMapper::class)->map($invoice->fresh(['customer', 'lines']), $configuration);

        $this->assertSame('Seller Limited', $payload['sellerBusinessName']);
        $this->assertSame('SINDH', $payload['sellerProvince']);
        $this->assertSame('1234567', $payload['buyerNTNCNIC']);
        $this->assertSame('9983.0000', $payload['items'][0]['hsCode']);
        $this->assertSame('1.000', $payload['items'][0]['quantity']);
        $this->assertSame('100.00', $payload['items'][0]['valueSalesExcludingST']);
    }

    public function test_fbr_accepted_invoice_is_immutable_and_repeat_submission_never_calls_provider_twice(): void
    {
        $context = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($context);
        config(['services.fbr.endpoints.sandbox' => 'https://sandbox.example.test/fbr']);
        FbrCompanyConfiguration::factory()->for($context['company'])->create(['updated_by' => $context['user']->id]);
        $gateway = new class implements FbrGateway
        {
            public int $calls = 0;

            public function submit(array $payload, string $idempotencyKey, FbrSubmissionContext $context): FbrSubmissionResult
            {
                $this->calls++;

                return new FbrSubmissionResult(FbrSubmissionStatus::Accepted, 'FBR-LOCKED', ['status' => 'accepted']);
            }
        };
        $this->app->instance(FbrGateway::class, $gateway);
        $headers = ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'accepted-once'];

        $this->postJson("/api/v1/accounting/fbr/invoices/{$invoiceId}/submit", [], $headers)->assertOk()->assertJsonPath('fbr_reference_number', 'FBR-LOCKED');
        $this->postJson("/api/v1/accounting/fbr/invoices/{$invoiceId}/submit", [], $headers)->assertOk();
        $this->patchJson("/api/v1/accounting/invoices/{$invoiceId}", $this->payload($context['customer']->id), ['X-Company-Id' => $context['company']->id])->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $this->deleteJson("/api/v1/accounting/invoices/{$invoiceId}", [], ['X-Company-Id' => $context['company']->id])->assertUnprocessable()->assertJsonValidationErrors('invoice');

        $this->assertSame(1, $gateway->calls);
        $this->assertDatabaseCount('fbr_submission_attempts', 1);
    }

    public function test_missing_company_configuration_returns_sanitized_unavailable_response_without_attempt(): void
    {
        $context = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($context);

        $this->postJson("/api/v1/accounting/fbr/invoices/{$invoiceId}/submit", [], ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'not-configured'])
            ->assertServiceUnavailable()->assertJsonPath('message', 'FBR submission is not configured for this company.');

        $this->assertDatabaseCount('fbr_submission_attempts', 0);
    }

    public function test_provider_metadata_is_allowlisted_and_secret_like_values_are_redacted(): void
    {
        $context = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($context);
        config(['services.fbr.endpoints.sandbox' => 'https://sandbox.example.test/fbr']);
        FbrCompanyConfiguration::factory()->for($context['company'])->create(['updated_by' => $context['user']->id]);
        $this->app->instance(FbrGateway::class, new class implements FbrGateway
        {
            public function submit(array $payload, string $idempotencyKey, FbrSubmissionContext $context): FbrSubmissionResult
            {
                return new FbrSubmissionResult(FbrSubmissionStatus::Rejected, null, ['status' => 'rejected', 'message' => 'token=provider-secret invalid', 'authorization' => 'Bearer provider-secret'], 'api_key=provider-secret invalid');
            }
        });

        $this->postJson("/api/v1/accounting/fbr/invoices/{$invoiceId}/submit", [], ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => 'sanitized-provider'])->assertOk();

        $stored = json_encode(FbrSubmissionAttempt::query()->sole()->only(['response_metadata', 'error_message']), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('provider-secret', $stored);
        $this->assertStringNotContainsString('authorization', $stored);
        $this->assertStringContainsString('[REDACTED]', $stored);
    }

    /** @param array<string, mixed> $context */
    private function createDraft(array $context): string
    {
        return (string) $this->postJson('/api/v1/accounting/invoices', $this->payload($context['customer']->id), ['X-Company-Id' => $context['company']->id, 'Idempotency-Key' => fake()->uuid()])->assertCreated()->json('id');
    }

    /** @return array<string, mixed> */
    private function payload(string $customerId): array
    {
        return ['customer_id' => $customerId, 'invoice_date' => '2026-09-22', 'due_date' => '2026-10-22', 'currency' => 'PKR', 'lines' => [['description' => 'Services', 'hs_code' => '9983.0000', 'fbr_rate_id' => '18', 'quantity_milli' => 1000, 'unit' => 'unit', 'unit_price' => 100000, 'discount' => 0, 'tax_rate_bps' => 1800, 'sales_type' => 'Standardized Goods']]];
    }
}
