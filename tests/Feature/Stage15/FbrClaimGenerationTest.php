<?php

namespace Tests\Feature\Stage15;

use App\Contracts\FbrGateway;
use App\Enums\FbrSubmissionStatus;
use App\Models\Company;
use App\Models\FbrCompanyConfiguration;
use App\Models\PakistanFbrInvoice;
use App\Models\PakistanFbrInvoiceLine;
use App\Models\PakistanFbrSubmissionAttempt;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Fbr\FbrSubmissionContext;
use App\Services\Fbr\FbrSubmissionResult;
use App\Services\Fbr\PakistanFbrPayloadMapper;
use App\Services\Fbr\PakistanFbrSubmissionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class FbrClaimGenerationTest extends TestCase
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
        $this->assertSame(1, PakistanFbrSubmissionAttempt::query()->sole()->claim_generation);
        $this->assertSame(1, $gateway->calls);
        $this->assertFirewall();
    }

    public function test_active_lease_does_not_acquire_another_generation_or_call_provider(): void
    {
        [$invoice, $user] = $this->draft();
        $gateway = $this->gateway(function () use ($invoice, $user): FbrSubmissionResult {
            $this->assertSame(FbrSubmissionStatus::Pending, $this->submit($invoice, $user)->fbr_status);
            $this->assertSame(1, PakistanFbrSubmissionAttempt::query()->sole()->claim_generation);

            return $this->accepted('ACTIVE');
        });

        $this->submit($invoice, $user);

        $this->assertSame(1, $gateway->calls);
        $this->assertSame(1, PakistanFbrSubmissionAttempt::query()->sole()->claim_generation);
        $this->assertFirewall();
    }

    public function test_only_generation_three_can_finalize_after_multiple_reclaims(): void
    {
        [$invoice, $user] = $this->draft();
        $generations = [];
        $gateway = $this->gateway(function () use ($invoice, $user, &$generations): FbrSubmissionResult {
            $generation = PakistanFbrSubmissionAttempt::query()->sole()->claim_generation;
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
        $this->assertSame(3, PakistanFbrSubmissionAttempt::query()->sole()->claim_generation);
        $this->assertSame('GENERATION-3', $result->fbr_reference_number);
        $this->assertSame(['status' => 'GENERATION-3'], $result->fbr_response_metadata);
        $this->assertSame('GENERATION-3', PakistanFbrSubmissionAttempt::query()->sole()->reference_number);
        $this->assertFirewall();
    }

    public function test_claim_audit_failure_rolls_back_new_attempt_and_generation(): void
    {
        [$invoice, $user] = $this->draft();
        $before = $invoice->fresh()->getAttributes();
        $gateway = $this->gateway(fn (): FbrSubmissionResult => $this->accepted('UNREACHABLE'));
        $this->mock(AuditService::class)->shouldReceive('recordOperation')->once()->andThrow(new \RuntimeException('Injected acquisition failure'));

        try {
            $this->submit($invoice, $user);
            $this->fail('The injected failure must abort acquisition.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected acquisition failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('pakistan_fbr_submission_attempts', 0);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame(0, $gateway->calls);
        $this->assertFirewall();
    }

    public function test_reclaim_rollback_preserves_previous_generation_and_state(): void
    {
        [$invoice, $user] = $this->draft();
        $gateway = $this->gateway(function () use ($invoice, $user): FbrSubmissionResult {
            $before = PakistanFbrSubmissionAttempt::query()->sole()->getAttributes();
            $this->travel(3)->minutes();
            $this->mock(AuditService::class)->shouldReceive('recordOperation')->once()->andThrow(new \RuntimeException('Injected reclaim failure'));
            try {
                $this->submit($invoice, $user);
                $this->fail('The injected failure must abort reclaim.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Injected reclaim failure', $exception->getMessage());
            } finally {
                $this->app->forgetInstance(AuditService::class);
            }
            $this->assertSame($before, PakistanFbrSubmissionAttempt::query()->sole()->getAttributes());

            return $this->accepted('ORIGINAL');
        });

        $this->assertSame('ORIGINAL', $this->submit($invoice, $user)->fbr_reference_number);
        $this->assertSame(1, $gateway->calls);
        $this->assertSame(1, PakistanFbrSubmissionAttempt::query()->sole()->claim_generation);
        $this->assertFirewall();
    }

    public function test_generation_exhaustion_fails_closed_without_provider_or_state_changes(): void
    {
        [$invoice, $user] = $this->draft();
        $configuration = FbrCompanyConfiguration::query()->sole();
        $payload = app(PakistanFbrPayloadMapper::class)->map($invoice->load('lines'), $configuration);
        $attempt = PakistanFbrSubmissionAttempt::factory()->for($invoice, 'invoice')->create([
            'company_id' => $invoice->company_id, 'idempotency_key' => 'generation-key',
            'status' => FbrSubmissionStatus::Failed, 'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);
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
            app(PakistanFbrSubmissionService::class)->submit($other->id, $user, $invoice, 'generation-key');
            $this->fail('Other company must not resolve this invoice.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('pakistan_fbr_submission_attempts', 0);
        }
        $this->assertSame(0, $gateway->calls);
        $this->assertFirewall();
    }

    /** @return array{PakistanFbrInvoice, User} */
    private function draft(): array
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-10-02 12:00:00');
        config(['services.fbr.pakistan_submission_enabled' => true, 'services.fbr.endpoints.sandbox' => 'https://fbr.example.test/submit']);
        $invoice = PakistanFbrInvoice::factory()->create();
        $user = User::query()->findOrFail($invoice->created_by);
        PakistanFbrInvoiceLine::factory()->for($invoice, 'invoice')->create();
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

    private function submit(PakistanFbrInvoice $invoice, User $user): PakistanFbrInvoice
    {
        return app(PakistanFbrSubmissionService::class)->submit($invoice->company_id, $user, $invoice, 'generation-key');
    }

    private function accepted(string $reference): FbrSubmissionResult
    {
        return new FbrSubmissionResult(FbrSubmissionStatus::Accepted, $reference, ['status' => $reference]);
    }

    private function assertFirewall(): void
    {
        foreach (['invoices', 'journals', 'journal_lines', 'customer_payments', 'inventory_movements', 'bank_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNothingSent();
    }
}
