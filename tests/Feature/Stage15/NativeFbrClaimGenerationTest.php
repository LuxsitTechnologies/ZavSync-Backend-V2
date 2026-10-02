<?php

namespace Tests\Feature\Stage15;

use App\Contracts\FbrGateway;
use App\Enums\FbrSubmissionStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\FbrUnavailableException;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FbrCompanyConfiguration;
use App\Models\FbrSubmissionAttempt;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\Fbr\FbrInvoiceService;
use App\Services\Fbr\FbrSubmissionContext;
use App\Services\Fbr\FbrSubmissionResult;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class NativeFbrClaimGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_completion_and_replay_keep_generation_one(): void
    {
        [$invoice, $user] = $this->draft();
        $gateway = $this->gateway(fn (): FbrSubmissionResult => $this->accepted('FIRST'));

        $result = $this->submit($invoice, $user);
        $this->submit($invoice, $user);

        $this->assertSame(FbrSubmissionStatus::Accepted, $result->fbr_status);
        $this->assertSame('FIRST', $result->fbr_reference_number);
        $this->assertSame(1, FbrSubmissionAttempt::query()->sole()->claim_generation);
        $this->assertSame(1, $gateway->calls);
        $this->assertFirewall();
    }

    public function test_active_lease_does_not_acquire_another_generation_or_call_provider(): void
    {
        [$invoice, $user] = $this->draft();
        $gateway = $this->gateway(function () use ($invoice, $user): FbrSubmissionResult {
            $this->assertSame(FbrSubmissionStatus::Pending, $this->submit($invoice, $user)->fbr_status);
            $this->assertSame(1, FbrSubmissionAttempt::query()->sole()->claim_generation);

            return $this->accepted('ACTIVE');
        });

        $this->submit($invoice, $user);

        $this->assertSame(1, $gateway->calls);
        $this->assertSame(1, FbrSubmissionAttempt::query()->sole()->claim_generation);
        $this->assertFirewall();
    }

    public function test_only_generation_three_can_finalize_after_multiple_reclaims(): void
    {
        [$invoice, $user] = $this->draft();
        $generations = [];
        $gateway = $this->gateway(function () use ($invoice, $user, &$generations): FbrSubmissionResult {
            $generation = FbrSubmissionAttempt::query()->sole()->claim_generation;
            $generations[] = $generation;
            if ($generation < 3) {
                $this->travel(3)->minutes();
                $this->submit($invoice, $user);
            }

            return $this->accepted('GENERATION-'.$generation);
        });

        $result = $this->submit($invoice, $user);

        $this->assertSame([1, 2, 3], $generations);
        $this->assertSame(3, $gateway->calls);
        $this->assertSame(3, FbrSubmissionAttempt::query()->sole()->claim_generation);
        $this->assertSame('GENERATION-3', $result->fbr_reference_number);
        $this->assertSame(['status' => 'GENERATION-3'], $result->fbr_response_metadata);
        $this->assertSame('GENERATION-3', FbrSubmissionAttempt::query()->sole()->reference_number);
        $this->assertFirewall();
    }

    public function test_different_key_cannot_bypass_active_claim(): void
    {
        [$invoice, $user] = $this->draft();
        $gateway = $this->gateway(function () use ($invoice, $user): FbrSubmissionResult {
            try {
                app(FbrInvoiceService::class)->submit($invoice->company_id, $user, $invoice, 'different-key');
                $this->fail('A different key must not bypass a pending claim.');
            } catch (ConflictHttpException $exception) {
                $this->assertStringContainsString('original idempotency key', $exception->getMessage());
            }
            $this->assertSame(1, FbrSubmissionAttempt::query()->sole()->claim_generation);

            return $this->accepted('ORIGINAL');
        });

        $this->submit($invoice, $user);
        $this->assertSame(1, $gateway->calls);
        $this->assertFirewall();
    }

    public function test_changed_payload_cannot_reuse_claim_key(): void
    {
        [$invoice, $user] = $this->draft();
        $gateway = $this->gateway(function () use ($invoice, $user): FbrSubmissionResult {
            $line = $invoice->lines()->sole();
            $description = $line->description;
            $line->update(['description' => 'Changed payload']);
            try {
                $this->submit($invoice, $user);
                $this->fail('A changed payload must not reuse the claim key.');
            } catch (ConflictHttpException $exception) {
                $this->assertStringContainsString('different FBR payload', $exception->getMessage());
            } finally {
                $line->update(['description' => $description]);
            }
            $this->assertSame(1, FbrSubmissionAttempt::query()->sole()->claim_generation);

            return $this->accepted('ORIGINAL');
        });

        $this->submit($invoice, $user);
        $this->assertSame(1, $gateway->calls);
        $this->assertFirewall();
    }

    public function test_claim_invoice_failure_rolls_back_new_attempt_and_generation(): void
    {
        [$invoice, $user] = $this->draft();
        $before = $invoice->fresh()->getAttributes();
        $gateway = $this->gateway(fn (): FbrSubmissionResult => $this->accepted('UNREACHABLE'));
        Event::listen('eloquent.saving: '.Invoice::class, static function (): void {
            throw new \RuntimeException('Injected acquisition failure');
        });

        try {
            $this->submit($invoice, $user);
            $this->fail('The injected failure must abort acquisition.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected acquisition failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('fbr_submission_attempts', 0);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame(0, $gateway->calls);
        $this->assertFirewall();
    }

    public function test_reclaim_rollback_preserves_previous_generation_and_state(): void
    {
        [$invoice, $user] = $this->draft();
        $gateway = $this->gateway(function () use ($invoice, $user): FbrSubmissionResult {
            $before = FbrSubmissionAttempt::query()->sole()->getAttributes();
            $this->travel(3)->minutes();
            Event::listen('eloquent.saving: '.Invoice::class, static function (): void {
                throw new \RuntimeException('Injected reclaim failure');
            });
            try {
                $this->submit($invoice, $user);
                $this->fail('The injected failure must abort reclaim.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Injected reclaim failure', $exception->getMessage());
            } finally {
                Event::forget('eloquent.saving: '.Invoice::class);
            }
            $this->assertSame($before, FbrSubmissionAttempt::query()->sole()->getAttributes());

            return $this->accepted('ORIGINAL');
        });

        $this->assertSame('ORIGINAL', $this->submit($invoice, $user)->fbr_reference_number);
        $this->assertSame(1, $gateway->calls);
        $this->assertSame(1, FbrSubmissionAttempt::query()->sole()->claim_generation);
        $this->assertFirewall();
    }

    public function test_generation_exhaustion_fails_closed_without_provider_or_state_changes(): void
    {
        [$invoice, $user] = $this->draft();
        $this->gateway(static function (): FbrSubmissionResult {
            throw new FbrUnavailableException('Synthetic failure');
        });
        try {
            $this->submit($invoice, $user);
        } catch (FbrUnavailableException) {
        }
        $attempt = FbrSubmissionAttempt::query()->sole();
        $attempt->forceFill(['claim_generation' => PHP_INT_MAX])->save();
        $before = $attempt->fresh()->getAttributes();
        $gateway = $this->gateway(fn (): FbrSubmissionResult => $this->accepted('UNREACHABLE'));

        try {
            $this->submit($invoice, $user);
            $this->fail('Exhausted generations must fail closed.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('exhausted', $exception->getMessage());
        }

        $this->assertSame($before, $attempt->fresh()->getAttributes());
        $this->assertSame(0, $gateway->calls);
        $this->assertFirewall();
    }

    public function test_other_company_cannot_acquire_a_generation(): void
    {
        [$invoice, $user] = $this->draft();
        $other = Company::factory()->create();
        $gateway = $this->gateway(fn (): FbrSubmissionResult => $this->accepted('UNREACHABLE'));

        try {
            app(FbrInvoiceService::class)->submit($other->id, $user, $invoice, 'generation-key');
            $this->fail('Other company must not resolve this invoice.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('fbr_submission_attempts', 0);
        }
        $this->assertSame(0, $gateway->calls);
        $this->assertFirewall();
    }

    /** @return array{Invoice, User} */
    private function draft(): array
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-10-02 12:00:00');
        config(['services.fbr.pakistan_submission_enabled' => true, 'services.fbr.endpoints.sandbox' => 'https://fbr.example.test/submit']);
        $company = Company::factory()->create();
        $customer = Customer::factory()->for($company)->create();
        $invoice = Invoice::factory()->for($company)->for($customer)->create();
        $user = User::query()->findOrFail($invoice->created_by);
        InvoiceLine::factory()->for($invoice, 'invoice')->create();
        FbrCompanyConfiguration::factory()->create(['company_id' => $invoice->company_id, 'updated_by' => $user->id]);

        return [$invoice, $user];
    }

    private function gateway(\Closure $callback): FbrGateway
    {
        $gateway = new class($callback) implements FbrGateway
        {
            public int $calls = 0;

            public function __construct(private \Closure $callback) {}

            public function submit(array $payload, string $idempotencyKey, FbrSubmissionContext $context): FbrSubmissionResult
            {
                $this->calls++;

                return ($this->callback)();
            }
        };
        $this->app->instance(FbrGateway::class, $gateway);

        return $gateway;
    }

    private function submit(Invoice $invoice, User $user): Invoice
    {
        return app(FbrInvoiceService::class)->submit($invoice->company_id, $user, $invoice, 'generation-key');
    }

    private function accepted(string $reference): FbrSubmissionResult
    {
        return new FbrSubmissionResult(FbrSubmissionStatus::Accepted, $reference, ['status' => $reference]);
    }

    private function assertFirewall(): void
    {
        foreach (['pakistan_fbr_invoices', 'pakistan_fbr_submission_attempts', 'journals', 'journal_lines', 'customer_payments', 'inventory_movements', 'bank_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(InvoiceStatus::Draft, Invoice::query()->sole()->status);
        $this->assertNull(Invoice::query()->sole()->journal_id);
        Http::assertNothingSent();
    }
}
