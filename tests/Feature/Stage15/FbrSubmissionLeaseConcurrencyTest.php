<?php

namespace Tests\Feature\Stage15;

use App\Contracts\FbrGateway;
use App\Enums\FbrSubmissionStatus;
use App\Exceptions\FbrUnavailableException;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FbrCompanyConfiguration;
use App\Models\FbrSubmissionAttempt;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PakistanFbrInvoice;
use App\Models\PakistanFbrInvoiceLine;
use App\Models\PakistanFbrSubmissionAttempt;
use App\Models\User;
use App\Services\Fbr\FbrInvoiceService;
use App\Services\Fbr\FbrSubmissionContext;
use App\Services\Fbr\FbrSubmissionResult;
use App\Services\Fbr\PakistanFbrSubmissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ResetsCommittedFixtures;
use Tests\TestCase;

class FbrSubmissionLeaseConcurrencyTest extends TestCase
{
    use ResetsCommittedFixtures;

    public function test_expired_worker_failure_cannot_overwrite_a_newer_accepted_claim(): void
    {
        $this->assertStaleCompletionIsFenced(false);
    }

    public function test_expired_worker_success_cannot_overwrite_a_newer_reference_or_metadata(): void
    {
        $this->assertStaleCompletionIsFenced(true);
    }

    public function test_server_selected_retry_fences_stale_worker_failure(): void
    {
        $this->assertStaleCompletionIsFenced(false, false, true);
    }

    public function test_server_selected_retry_fences_stale_worker_success(): void
    {
        $this->assertStaleCompletionIsFenced(true, false, true);
    }

    public function test_native_accounting_fbr_expired_worker_cannot_overwrite_a_newer_accepted_claim(): void
    {
        $this->assertStaleCompletionIsFenced(false, true);
    }

    public function test_native_accounting_fbr_stale_success_cannot_overwrite_newer_reference_or_metadata(): void
    {
        $this->assertStaleCompletionIsFenced(true, true);
    }

    private function assertStaleCompletionIsFenced(bool $staleSuccess, bool $nativeAccounting = false, bool $serverRetry = false): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Local two-process reproduction requires pcntl, posix and Unix sockets.');
        }
        $mariadb = defined('ZAVSYNC_MARIADB_CERTIFICATION');
        if ($mariadb && getenv('MARIADB_CERTIFICATION_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Execute via php tests/mariadb.php concurrency.');
        }
        $database = tempnam(sys_get_temp_dir(), 'zavsync-lease-');
        $this->assertIsString($database);
        $originalConnection = config('database.default');
        $connection = $mariadb ? 'mysql' : 'stage15_lease_probe';
        if (! $mariadb) {
            config([
                'database.default' => 'stage15_lease_probe',
                'database.connections.stage15_lease_probe' => [
                    'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true,
                ],
            ]);
        }
        config(['services.fbr.pakistan_submission_enabled' => true, 'services.fbr.endpoints.sandbox' => 'https://fbr.example.test/submit']);
        Http::preventStrayRequests();
        $worker = null;
        $channel = null;

        try {
            $this->artisan($mariadb ? 'migrate:fresh' : 'migrate', ['--database' => $connection, '--no-interaction' => true])->assertSuccessful();
            $this->travelTo('2026-10-02 12:00:00');
            $attemptModel = $nativeAccounting ? FbrSubmissionAttempt::class : PakistanFbrSubmissionAttempt::class;
            $service = $nativeAccounting ? FbrInvoiceService::class : PakistanFbrSubmissionService::class;
            if ($nativeAccounting) {
                $company = Company::factory()->create();
                $customer = Customer::factory()->for($company)->create();
                $invoice = Invoice::factory()->for($company)->for($customer)->create();
                InvoiceLine::factory()->for($invoice)->create();
            } else {
                $invoice = PakistanFbrInvoice::factory()->create();
                PakistanFbrInvoiceLine::factory()->for($invoice, 'invoice')->create();
            }
            $user = User::query()->findOrFail($invoice->created_by);
            FbrCompanyConfiguration::factory()->create(['company_id' => $invoice->company_id, 'updated_by' => $user->id]);
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $this->assertIsArray($pair);
            [$channel, $childChannel] = $pair;
            stream_set_timeout($channel, 15);
            stream_set_timeout($childChannel, 15);
            DB::purge($connection);
            $worker = pcntl_fork();
            $this->assertNotSame(-1, $worker);

            if ($worker === 0) {
                fclose($channel);
                pcntl_async_signals(true);
                pcntl_signal(SIGALRM, static function (): void {
                    exit(124);
                });
                pcntl_alarm(20);
                $gateway = new class($childChannel, $staleSuccess) implements FbrGateway
                {
                    /** @param resource $channel */
                    public function __construct(private mixed $channel, private bool $staleSuccess) {}

                    public function submit(array $payload, string $idempotencyKey, FbrSubmissionContext $context): FbrSubmissionResult
                    {
                        fwrite($this->channel, "claimed\n");
                        if (fgets($this->channel) !== "release\n") {
                            throw new \RuntimeException('Worker barrier timed out.');
                        }
                        if ($this->staleSuccess) {
                            return new FbrSubmissionResult(FbrSubmissionStatus::Accepted, 'STALE-REFERENCE', ['worker' => 'stale']);
                        }
                        throw new FbrUnavailableException('Synthetic expired-worker failure.');
                    }
                };
                $this->app->instance(FbrGateway::class, $gateway);
                try {
                    app($service)->submit($invoice->company_id, $user, $invoice, 'lease-retry');
                    fwrite($childChannel, "finished\n");
                    exit($staleSuccess ? 0 : 2);
                } catch (FbrUnavailableException) {
                    fwrite($childChannel, "finished\n");
                    exit(0);
                } catch (\Throwable $exception) {
                    fwrite($childChannel, get_class($exception)."\n");
                    exit(3);
                }
            }

            fclose($childChannel);
            $this->assertSame("claimed\n", fgets($channel));
            $this->assertSame(FbrSubmissionStatus::Pending, $invoice->fresh()->fbr_status);
            $this->assertSame(1, $attemptModel::query()->sole()->claim_generation);
            $this->travelTo('2026-10-02 12:03:00');
            $gateway = new class implements FbrGateway
            {
                public int $calls = 0;

                public function submit(array $payload, string $idempotencyKey, FbrSubmissionContext $context): FbrSubmissionResult
                {
                    $this->calls++;

                    return new FbrSubmissionResult(FbrSubmissionStatus::Accepted, 'NEWER-CLAIM-ACCEPTED', ['status' => 'accepted']);
                }
            };
            $this->app->instance(FbrGateway::class, $gateway);
            $newer = $serverRetry
                ? app(PakistanFbrSubmissionService::class)->retry($invoice->company_id, $user, $invoice)
                : app($service)->submit($invoice->company_id, $user, $invoice, 'lease-retry');
            $this->assertSame(FbrSubmissionStatus::Accepted, $newer->fbr_status);
            $this->assertSame('NEWER-CLAIM-ACCEPTED', $newer->fbr_reference_number);
            $this->assertSame(1, $gateway->calls);
            $this->assertSame(2, $attemptModel::query()->sole()->claim_generation);
            fwrite($channel, "release\n");
            $this->assertSame("finished\n", fgets($channel));
            pcntl_waitpid($worker, $status);
            $worker = null;
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertSame(1, $attemptModel::query()->count());
            $this->assertDatabaseCount('invoices', $nativeAccounting ? 1 : 0);
            foreach (['journal_lines', 'journals', 'customer_payments', 'inventory_movements', 'bank_transactions'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
            Http::assertNothingSent();
            $this->assertSame(['status' => 'accepted'], $invoice->fresh()->fbr_response_metadata);
            $this->assertSame(['status' => 'accepted'], $attemptModel::query()->sole()->response_metadata);
            $this->assertSame(
                ['invoice_status' => 'accepted', 'attempt_status' => 'accepted', 'reference' => 'NEWER-CLAIM-ACCEPTED'],
                [
                    'invoice_status' => $invoice->fresh()->fbr_status->value,
                    'attempt_status' => $attemptModel::query()->sole()->status->value,
                    'reference' => $invoice->fresh()->fbr_reference_number,
                ],
                'An expired worker must not overwrite the newer accepted claim.',
            );
        } finally {
            if (is_int($worker) && $worker > 0) {
                posix_kill($worker, SIGKILL);
                pcntl_waitpid($worker, $status);
            }
            if (is_resource($channel)) {
                fclose($channel);
            }
            DB::purge($connection);
            config(['database.default' => $originalConnection]);
            $this->travelBack();
            unlink($database);
            if ($mariadb) {
                $this->resetCommittedFixtures($connection);
            }
        }
    }
}
