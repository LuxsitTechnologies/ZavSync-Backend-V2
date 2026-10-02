<?php

namespace Tests\Feature\Stage15;

use App\Contracts\FbrGateway;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\LegacyImportRun;
use App\Models\User;
use App\Services\Fbr\FbrSubmissionContext;
use App\Services\Fbr\FbrSubmissionResult;
use App\Services\Migration\LegacyInvoiceImportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Concerns\BuildsLegacyInvoiceSnapshots;
use Tests\Concerns\ResetsCommittedFixtures;
use Tests\TestCase;

class LegacyImportOwnershipConcurrencyTest extends TestCase
{
    use BuildsLegacyInvoiceSnapshots, ResetsCommittedFixtures;

    public function test_old_import_worker_failure_cannot_overwrite_a_completed_resume(): void
    {
        $this->assertOwnership('failure');
    }

    public function test_old_worker_cannot_write_progress_after_resume(): void
    {
        $this->assertOwnership('progress');
    }

    public function test_old_worker_cannot_complete_after_resume(): void
    {
        $this->assertOwnership('completion');
    }

    public function test_multiple_resumes_preserve_generation_three(): void
    {
        $this->assertOwnership('multiple');
    }

    private function assertOwnership(string $phase): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Local two-process reproduction requires pcntl, posix and Unix sockets.');
        }
        $mariadb = defined('ZAVSYNC_MARIADB_CERTIFICATION');
        if ($mariadb && getenv('MARIADB_CERTIFICATION_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Execute via php tests/mariadb.php concurrency.');
        }
        $database = tempnam(sys_get_temp_dir(), 'zavsync-import-');
        $this->assertIsString($database);
        $originalConnection = config('database.default');
        $connection = $mariadb ? 'mysql' : 'stage15_import_probe';
        if (! $mariadb) {
            config([
                'database.default' => 'stage15_import_probe',
                'database.connections.stage15_import_probe' => [
                    'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true,
                ],
            ]);
        }
        Http::preventStrayRequests();
        $gateway = new class implements FbrGateway
        {
            public int $calls = 0;

            public function submit(array $payload, string $idempotencyKey, FbrSubmissionContext $context): FbrSubmissionResult
            {
                $this->calls++;
                throw new \LogicException('Historical imports must never call a provider.');
            }
        };
        $this->app->instance(FbrGateway::class, $gateway);
        $worker = null;
        $channel = null;

        try {
            $this->artisan($mariadb ? 'migrate:fresh' : 'migrate', ['--database' => $connection, '--no-interaction' => true])->assertSuccessful();
            $company = Company::factory()->create();
            $user = User::factory()->create();
            config(['legacy_migration.chunk_size' => 1]);
            $snapshot = $this->legacySnapshot(2);
            $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
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
                $pause = static function (string $runId) use ($childChannel, $phase): void {
                    DB::afterCommit(static function () use ($childChannel, $runId, $phase): void {
                        fwrite($childChannel, $runId."\n");
                        if (fgets($childChannel) !== "release\n") {
                            throw new \RuntimeException('Import barrier timed out.');
                        }
                        if ($phase !== 'failure') {
                            return;
                        }
                        $injected = false;
                        DB::connection()->beforeExecuting(static function (string $query) use (&$injected): void {
                            if (! $injected && str_starts_with($query, 'select') && (str_contains($query, '"companies"') || str_contains($query, '`companies`'))) {
                                $injected = true;
                                throw new \RuntimeException('Injected old-worker database failure');
                            }
                        });
                    });
                };
                if (in_array($phase, ['failure', 'multiple'], true)) {
                    Event::listen('eloquent.created: '.AuditLog::class, static function (AuditLog $audit) use ($pause): void {
                        if ($audit->action === 'legacy_invoice_import_started') {
                            $pause($audit->entity_id);
                        }
                    });
                } else {
                    Event::listen('eloquent.updated: '.LegacyImportRun::class, static function (LegacyImportRun $run) use ($pause, $phase): void {
                        if ($run->status === 'RUNNING' && ($run->progress['invoices_processed'] ?? 0) === ($phase === 'progress' ? 1 : 2)) {
                            $pause($run->id);
                        }
                    });
                }
                try {
                    app(LegacyInvoiceImportService::class)->execute($snapshot, $company, $user, '4', $fingerprint, 'synthetic.json');
                    exit(2);
                } catch (\Throwable $exception) {
                    $expected = $phase === 'failure'
                        ? $exception->getMessage() === 'Injected old-worker database failure'
                        : $exception instanceof ConflictHttpException;
                    fwrite($childChannel, ($expected ? 'stale-stopped' : get_class($exception))."\n");
                    fwrite($childChannel, 'provider-calls:'.$gateway->calls."\n");
                    exit($expected && $gateway->calls === 0 ? 0 : 3);
                }
            }

            fclose($childChannel);
            $runId = trim((string) fgets($channel));
            $run = LegacyImportRun::query()->findOrFail($runId);
            $this->assertSame('RUNNING', $run->status);
            $this->assertSame(1, $run->execution_generation);
            if ($phase === 'multiple') {
                Event::listen('eloquent.created: '.AuditLog::class, static function (AuditLog $audit): void {
                    if ($audit->action === 'legacy_invoice_import_started') {
                        throw new \RuntimeException('Injected second-generation interruption');
                    }
                });
                try {
                    app(LegacyInvoiceImportService::class)->execute($snapshot, $company, $user, '4', $fingerprint, 'synthetic.json', resumeRunId: $runId);
                    $this->fail('Second generation must encounter the injected interruption.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame('Injected second-generation interruption', $exception->getMessage());
                } finally {
                    Event::forget('eloquent.created: '.AuditLog::class);
                }
                $this->assertSame(2, $run->fresh()->execution_generation);
            }
            $report = app(LegacyInvoiceImportService::class)->execute($snapshot, $company, $user, '4', $fingerprint, 'synthetic.json', resumeRunId: $runId);
            $this->assertSame($runId, $report['run_id']);
            $this->assertSame('COMPLETED', $run->fresh()->status);
            $this->assertSame($phase === 'multiple' ? 3 : 2, $run->fresh()->execution_generation);
            $completed = $run->fresh()->getAttributes();
            fwrite($channel, "release\n");
            $this->assertSame("stale-stopped\n", fgets($channel));
            $this->assertSame("provider-calls:0\n", fgets($channel));
            pcntl_waitpid($worker, $status);
            $worker = null;
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertSame(0, $gateway->calls);
            Http::assertNothingSent();
            foreach (['invoices', 'journals', 'journal_lines', 'customer_payments', 'inventory_movements', 'bank_transactions', 'pakistan_fbr_submission_attempts', 'fbr_submission_attempts'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
            $this->assertDatabaseCount('pakistan_fbr_invoices', 2);
            $this->assertDatabaseCount('pakistan_fbr_invoice_lines', 2);
            $this->assertDatabaseCount('legacy_fbr_evidence', 2);
            $this->assertDatabaseCount('legacy_entity_maps', 7);
            $this->assertDatabaseCount('legacy_import_runs', 1);
            $this->assertSame($completed, $run->fresh()->getAttributes(), 'The old worker must not overwrite the completed resume.');
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
            unlink($database);
            if ($mariadb) {
                $this->resetCommittedFixtures($connection);
            }
        }
    }
}
