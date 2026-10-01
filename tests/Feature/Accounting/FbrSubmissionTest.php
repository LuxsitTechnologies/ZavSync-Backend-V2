<?php

namespace Tests\Feature\Accounting;

use App\Contracts\FbrGateway;
use App\Enums\FbrSubmissionStatus;
use App\Exceptions\FbrUnavailableException;
use App\Models\FbrCompanyConfiguration;
use App\Services\Fbr\FbrSubmissionContext;
use App\Services\Fbr\FbrSubmissionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FbrSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_submission_is_audited_and_does_not_post_accounting(): void
    {
        $context = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($context);
        $gateway = new class implements FbrGateway
        {
            public int $calls = 0;

            public function submit(array $payload, string $idempotencyKey, FbrSubmissionContext $context): FbrSubmissionResult
            {
                $this->calls++;

                return new FbrSubmissionResult(FbrSubmissionStatus::Accepted, 'FBR-IRN-1001', ['status' => 'accepted', 'invoiceNumber' => 'FBR-IRN-1001']);
            }
        };
        $this->app->instance(FbrGateway::class, $gateway);

        $response = $this->postJson("/api/v1/accounting/fbr/invoices/$invoiceId/submit", [], $this->headers($context['company']->id, 'fbr-submit-1'));

        $response->assertOk()->assertJsonPath('fbr_status', 'accepted')->assertJsonPath('fbr_reference_number', 'FBR-IRN-1001')->assertJsonMissing(['token' => 'top-secret-token']);
        $this->assertSame(1, $gateway->calls);
        $this->assertDatabaseCount('fbr_submission_attempts', 1);
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'status' => 'draft', 'fbr_status' => 'accepted']);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $invoiceId, 'action' => 'submitted', 'module' => 'fbr']);
    }

    public function test_completed_fbr_retry_is_idempotent_and_does_not_call_gateway_twice(): void
    {
        $context = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($context);
        $gateway = new class implements FbrGateway
        {
            public int $calls = 0;

            public function submit(array $payload, string $idempotencyKey, FbrSubmissionContext $context): FbrSubmissionResult
            {
                $this->calls++;

                return new FbrSubmissionResult(FbrSubmissionStatus::Accepted, 'FBR-SAME', ['status' => 'accepted']);
            }
        };
        $this->app->instance(FbrGateway::class, $gateway);
        $headers = $this->headers($context['company']->id, 'fbr-repeat');

        $this->postJson("/api/v1/accounting/fbr/invoices/$invoiceId/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/accounting/fbr/invoices/$invoiceId/submit", [], $headers)->assertOk()->assertJsonPath('fbr_reference_number', 'FBR-SAME');

        $this->assertSame(1, $gateway->calls);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('fbr_submission_attempts', 1);
    }

    public function test_unavailable_fbr_marks_attempt_failed_without_creating_journal_and_can_retry(): void
    {
        $context = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($context);
        $gateway = new class implements FbrGateway
        {
            public bool $unavailable = true;

            public function submit(array $payload, string $idempotencyKey, FbrSubmissionContext $context): FbrSubmissionResult
            {
                if ($this->unavailable) {
                    throw new FbrUnavailableException('FBR timeout. Retry safely.');
                }

                return new FbrSubmissionResult(FbrSubmissionStatus::Accepted, 'FBR-RETRY', ['status' => 'accepted']);
            }
        };
        $this->app->instance(FbrGateway::class, $gateway);
        $headers = $this->headers($context['company']->id, 'fbr-retry');

        $this->postJson("/api/v1/accounting/fbr/invoices/$invoiceId/submit", [], $headers)->assertServiceUnavailable()->assertJsonPath('message', 'FBR timeout. Retry safely.');
        $this->assertDatabaseHas('fbr_submission_attempts', ['invoice_id' => $invoiceId, 'status' => 'failed']);
        $this->assertDatabaseCount('journals', 0);

        $gateway->unavailable = false;
        $this->postJson("/api/v1/accounting/fbr/invoices/$invoiceId/submit", [], $headers)->assertOk()->assertJsonPath('fbr_status', 'accepted');
        $this->assertDatabaseCount('fbr_submission_attempts', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_fbr_rejection_is_stored_as_separate_submission_state(): void
    {
        $context = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($context);
        $this->app->instance(FbrGateway::class, new class implements FbrGateway
        {
            public function submit(array $payload, string $idempotencyKey, FbrSubmissionContext $context): FbrSubmissionResult
            {
                return new FbrSubmissionResult(FbrSubmissionStatus::Rejected, null, ['status' => 'rejected', 'errors' => ['Invalid buyer NTN']], 'Invalid buyer NTN');
            }
        });

        $this->postJson("/api/v1/accounting/fbr/invoices/$invoiceId/submit", [], $this->headers($context['company']->id, 'fbr-rejected'))->assertOk()->assertJsonPath('fbr_status', 'rejected')->assertJsonPath('accounting_status', 'draft');

        $this->assertDatabaseHas('fbr_submission_attempts', ['invoice_id' => $invoiceId, 'status' => 'rejected', 'error_message' => 'Invalid buyer NTN']);
    }

    public function test_fbr_submission_requires_a_customer_ntn_or_cnic(): void
    {
        $context = $this->stage3AccountingContext();
        $context['customer']->update(['ntn' => null, 'cnic' => null]);
        $invoiceId = $this->createDraft($context);

        $this->postJson("/api/v1/accounting/fbr/invoices/$invoiceId/submit", [], $this->headers($context['company']->id, 'fbr-no-identity'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer');

        $this->assertDatabaseCount('fbr_submission_attempts', 0);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_company_cannot_submit_another_company_invoice_to_fbr(): void
    {
        $owner = $this->stage3AccountingContext();
        $invoiceId = $this->createDraft($owner);
        $outsider = $this->stage3AccountingContext();

        $this->postJson("/api/v1/accounting/fbr/invoices/$invoiceId/submit", [], $this->headers($outsider['company']->id, 'fbr-cross-company'))
            ->assertNotFound();

        $this->assertDatabaseCount('fbr_submission_attempts', 0);
    }

    /** @param array<string, mixed> $context */
    private function createDraft(array $context): string
    {
        config(['services.fbr.endpoints.sandbox' => 'https://sandbox.example.test/fbr']);
        FbrCompanyConfiguration::factory()->for($context['company'])->create(['updated_by' => $context['user']->id]);
        $payload = ['customer_id' => $context['customer']->id, 'invoice_date' => '2026-09-22', 'due_date' => '2026-10-22', 'currency' => 'PKR', 'lines' => [['description' => 'Services', 'hs_code' => '9983.0000', 'fbr_rate_id' => '18', 'quantity_milli' => 1000, 'unit' => 'unit', 'unit_price' => 100000, 'discount' => 0, 'tax_rate_bps' => 1800, 'sales_type' => 'Standardized Goods']]];

        return (string) $this->postJson('/api/v1/accounting/invoices', $payload, $this->headers($context['company']->id, 'create-'.fake()->uuid()))->assertCreated()->json('id');
    }

    /** @return array<string, string> */
    private function headers(string $companyId, string $idempotencyKey): array
    {
        return ['X-Company-Id' => $companyId, 'Idempotency-Key' => $idempotencyKey];
    }
}
