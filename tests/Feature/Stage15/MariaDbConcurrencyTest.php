<?php

namespace Tests\Feature\Stage15;

use App\Contracts\FbrGateway;
use App\Enums\FbrSubmissionStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FbrCompanyConfiguration;
use App\Models\LegacyEntityMap;
use App\Models\LegacyImportRun;
use App\Models\PakistanFbrInvoice;
use App\Models\PakistanFbrInvoiceLine;
use App\Models\User;
use App\Services\Accounting\InvoiceService;
use App\Services\Fbr\FbrSubmissionContext;
use App\Services\Fbr\FbrSubmissionResult;
use App\Services\Fbr\PakistanFbrInvoiceService;
use App\Services\Fbr\PakistanFbrSubmissionService;
use App\Services\Migration\LegacyInvoiceImportService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Concerns\BuildsLegacyInvoiceSnapshots;
use Tests\Concerns\CoordinatesDatabaseWorkers;
use Tests\MariaDbCertification;
use Tests\TestCase;

class MariaDbConcurrencyTest extends TestCase
{
    use BuildsLegacyInvoiceSnapshots, CoordinatesDatabaseWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('ZAVSYNC_MARIADB_CERTIFICATION') || getenv('MARIADB_CERTIFICATION_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Requires the guarded manual MariaDB concurrency mode.');
        }
        $this->assertTrue(function_exists('pcntl_fork') && function_exists('posix_kill') && function_exists('stream_socket_pair'), 'Concurrency certification requires pcntl, posix and Unix sockets.');
        MariaDbCertification::guard($this->app);
        $this->artisan('migrate:fresh', ['--database' => 'mysql', '--no-interaction' => true])->assertSuccessful();
        Http::preventStrayRequests();
        $this->mock(FbrGateway::class)->shouldNotReceive('submit');
    }

    protected function tearDown(): void
    {
        $this->stopDatabaseWorkers();
        parent::tearDown();
    }

    /** @return array<string, array{bool, bool, bool}> */
    public static function numberingCases(): array
    {
        return [
            'native same company' => [true, true, false], 'native distinct companies' => [true, false, false],
            'native replay' => [true, true, true], 'FBR same company' => [false, true, false],
            'FBR distinct companies' => [false, false, false], 'FBR replay' => [false, true, true],
        ];
    }

    #[DataProvider('numberingCases')]
    public function test_number_allocation_races(bool $native, bool $sameCompany, bool $sameKey): void
    {
        $company = Company::factory()->create();
        $other = $sameCompany ? $company : Company::factory()->create();
        $user = User::factory()->create();
        $data = $this->invoiceData();
        $data['customer_id'] = Customer::factory()->for($company)->create()->id;
        $otherData = $data;
        if (! $sameCompany) {
            $otherData['customer_id'] = Customer::factory()->for($other)->create()->id;
        }
        $service = $native ? InvoiceService::class : PakistanFbrInvoiceService::class;
        $first = $this->startDatabaseWorker(function ($channel) use ($company, $user, $data, $service): array {
            $paused = false;
            DB::listen(function (QueryExecuted $query) use ($channel, &$paused): void {
                if (! $paused && str_contains($query->sql, '`companies`') && str_contains(strtolower($query->sql), 'for update')) {
                    $paused = true;
                    $this->writeBarrier($channel, ['event' => 'locked']);
                    $this->readBarrier($channel, 'release');
                }
            });
            $invoice = app($service)->create($company->id, $user, $data, 'number-A');

            return $invoice->only(['id', 'sequence', 'invoice_number', 'company_id']);
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($other, $user, $otherData, $service, $sameKey): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            $invoice = app($service)->create($other->id, $user, $otherData, $sameKey ? 'number-A' : 'number-B');

            return $invoice->only(['id', 'sequence', 'invoice_number', 'company_id']);
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        if (! $sameCompany) {
            $b = $this->finishWorker($second);
        }
        $this->signalWorker($first, 'release');
        $a = $this->finishWorker($first);
        $b ??= $this->finishWorker($second);
        $this->assertSame($company->id, $a['company_id']);
        $this->assertSame($other->id, $b['company_id']);
        if ($sameKey) {
            $this->assertSame($a['id'], $b['id']);
        } else {
            $this->assertNotSame($a['id'], $b['id']);
        }
        $sequences = [$a['sequence'], $b['sequence']];
        sort($sequences);
        $this->assertSame($sameCompany && ! $sameKey ? [1, 2] : [1, 1], $sequences);
        $this->assertDatabaseCount($native ? 'invoices' : 'pakistan_fbr_invoices', $sameKey ? 1 : 2);
        $this->firewall($native ? ($sameKey ? 1 : 2) : 0);
    }

    public function test_competing_source_company_mappings_have_one_winner(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $user = User::factory()->create();
        $snapshot = $this->legacySnapshot();
        $first = $this->startDatabaseWorker(function ($channel) use ($a, $user, $snapshot): array {
            Event::listen('eloquent.creating: '.LegacyEntityMap::class, function (LegacyEntityMap $map) use ($channel): void {
                if ($map->source_entity_type === 'company') {
                    $this->writeBarrier($channel, ['event' => 'mapping']);
                    $this->readBarrier($channel, 'release');
                }
            });

            return $this->importResult($snapshot, $a, $user, 'map-A');
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'mapping');
        $second = $this->startDatabaseWorker(function ($channel) use ($b, $user, $snapshot): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);

            return $this->importResult($snapshot, $b, $user, 'map-B');
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');
        $results = [$this->finishWorker($first)['state'], $this->finishWorker($second)['state']];
        sort($results);
        $this->assertSame(['conflict', 'ok'], $results);
        $this->assertSame(1, LegacyEntityMap::query()->where('source_entity_type', 'company')->count());
        $this->assertDatabaseCount('pakistan_fbr_invoices', 1);
        $this->firewall();
    }

    public function test_duplicate_fingerprint_creates_one_logical_import(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $snapshot = $this->legacySnapshot();
        $first = $this->startDatabaseWorker(function ($channel) use ($company, $user, $snapshot): array {
            Event::listen('eloquent.creating: '.LegacyImportRun::class, function () use ($channel): void {
                $this->writeBarrier($channel, ['event' => 'acquired']);
                $this->readBarrier($channel, 'release');
            });

            return $this->importResult($snapshot, $company, $user, 'same');
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'acquired');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $user, $snapshot): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);

            return $this->importResult($snapshot, $company, $user, 'same');
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');
        $this->assertSame('ok', $this->finishWorker($first)['state']);
        $this->assertContains($this->finishWorker($second)['state'], ['ok', 'conflict']);
        foreach (['legacy_import_runs', 'pakistan_fbr_invoices', 'pakistan_fbr_invoice_lines', 'legacy_fbr_evidence'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseCount('legacy_entity_maps', 4);
        $this->firewall();
    }

    /** @return array<string, array{string}> */
    public static function submissionCases(): array
    {
        return ['same key' => ['same'], 'different key' => ['different'], 'changed payload' => ['changed']];
    }

    #[DataProvider('submissionCases')]
    public function test_pending_submission_races_make_one_provider_call(string $mode): void
    {
        $invoice = PakistanFbrInvoice::factory()->create();
        PakistanFbrInvoiceLine::factory()->for($invoice, 'invoice')->create();
        $user = User::query()->findOrFail($invoice->created_by);
        FbrCompanyConfiguration::factory()->create(['company_id' => $invoice->company_id, 'updated_by' => $user->id]);
        config(['services.fbr.pakistan_submission_enabled' => true, 'services.fbr.endpoints.sandbox' => 'https://fbr.example.test/submit']);
        $first = $this->startDatabaseWorker(function ($channel) use ($invoice, $user): array {
            $calls = 0;
            $fake = new class(function () use ($channel, &$calls): void {
                $calls++;
                $this->writeBarrier($channel, ['event' => 'provider']);
                $this->readBarrier($channel, 'release');
            }) implements FbrGateway {

                public function __construct(private \Closure $barrier) {}

                public function submit(array $payload, string $idempotencyKey, FbrSubmissionContext $context): FbrSubmissionResult
                {
                    ($this->barrier)();

                    return new FbrSubmissionResult(FbrSubmissionStatus::Accepted, 'FAKE-ONE', ['status' => 'accepted']);
                }
            };
            $this->app->instance(FbrGateway::class, $fake);
            app(PakistanFbrSubmissionService::class)->submit($invoice->company_id, $user, $invoice, 'race-key');

            return ['calls' => $calls];
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'provider');
        $second = $this->startDatabaseWorker(function ($channel) use ($invoice, $user, $mode): array {
            if ($mode === 'changed') {
                FbrCompanyConfiguration::query()->where('company_id', $invoice->company_id)->update(['seller_business_name' => 'Changed configuration']);
            }
            try {
                $result = app(PakistanFbrSubmissionService::class)->submit($invoice->company_id, $user, $invoice, $mode === 'different' ? 'other-key' : 'race-key');

                return ['state' => $result->fbr_status->value];
            } catch (ConflictHttpException) {
                return ['state' => 'conflict'];
            }
        });
        $this->signalWorker($second, 'go');
        $this->assertSame($mode === 'same' ? 'pending' : 'conflict', $this->finishWorker($second)['state']);
        $this->signalWorker($first, 'release');
        $this->assertSame(1, $this->finishWorker($first)['calls']);
        $this->assertSame(FbrSubmissionStatus::Accepted, $invoice->fresh()->fbr_status);
        $this->assertSame('FAKE-ONE', $invoice->fresh()->fbr_reference_number);
        $this->assertDatabaseCount('pakistan_fbr_submission_attempts', 1);
        $this->firewall();
    }

    public function test_controlled_deadlock_has_one_explicit_victim_and_no_partial_commit(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $workers = [];
        foreach ([[$a, $b, 'winner-A'], [$b, $a, 'winner-B']] as [$first, $second, $name]) {
            $workers[] = $this->startDatabaseWorker(function ($channel) use ($first, $second, $name): array {
                $attempts = 0;
                try {
                    DB::transaction(function () use ($channel, $first, $second, $name, &$attempts): void {
                        $attempts++;
                        Company::query()->whereKey($first->id)->lockForUpdate()->firstOrFail()->update(['name' => $name]);
                        $this->writeBarrier($channel, ['event' => 'first-lock']);
                        $this->readBarrier($channel, 'second-lock');
                        Company::query()->whereKey($second->id)->lockForUpdate()->firstOrFail()->update(['name' => $name]);
                    }, 1);

                    return ['state' => 'committed', 'name' => $name, 'attempts' => $attempts];
                } catch (QueryException $exception) {
                    if ((int) ($exception->errorInfo[1] ?? 0) !== 1213) {
                        throw $exception;
                    }

                    return ['state' => 'deadlock', 'attempts' => $attempts];
                }
            });
        }
        foreach ($workers as $worker) {
            $this->signalWorker($worker, 'go');
        }
        foreach ($workers as $worker) {
            $this->awaitWorker($worker, 'first-lock');
        }
        foreach ($workers as $worker) {
            $this->signalWorker($worker, 'second-lock');
        }
        $results = array_map(fn (int $worker): array => $this->finishWorker($worker), $workers);
        $this->assertSame(1, count(array_filter($results, fn (array $result): bool => $result['state'] === 'deadlock')));
        $winner = array_values(array_filter($results, fn (array $result): bool => $result['state'] === 'committed'))[0];
        $this->assertSame($winner['name'], $a->fresh()->name);
        $this->assertSame($winner['name'], $b->fresh()->name);
        $this->assertSame([1, 1], array_column($results, 'attempts'));
        $this->firewall();
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function importResult(array $snapshot, Company $company, User $user, string $fingerprint): array
    {
        try {
            app(LegacyInvoiceImportService::class)->execute($snapshot, $company, $user, '4', hash('sha256', $fingerprint), 'synthetic.json');

            return ['state' => 'ok'];
        } catch (ValidationException) {
            return ['state' => 'conflict'];
        }
    }

    /** @return array<string, mixed> */
    private function invoiceData(): array
    {
        return ['invoice_date' => '2026-10-02', 'due_date' => '2026-11-02', 'currency' => 'PKR', 'sale_type' => 'Goods at standard rate (default)',
            'invoice_type' => 'Sale Invoice', 'origin_province' => 'SINDH', 'destination_province' => 'SINDH',
            'buyer_snapshot' => ['registration_number' => '1234567', 'name' => 'Synthetic buyer'],
            'lines' => [['description' => 'Synthetic service', 'hs_code' => '9983.0000', 'quantity_milli' => 1000, 'unit' => 'unit', 'unit_price' => 10000, 'tax_rate_bps' => 1800, 'sales_type' => 'Goods']]];
    }

    private function firewall(int $nativeInvoices = 0): void
    {
        $this->assertDatabaseCount('invoices', $nativeInvoices);
        foreach (['journals', 'journal_lines', 'customer_payments', 'inventory_movements', 'bank_transactions', 'fbr_submission_attempts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNothingSent();
    }
}
