<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CoordinatesDatabaseWorkers;
use Tests\Concerns\ResetsCommittedFixtures;
use Tests\TestCase;

class ConcurrencyIsolationTest extends TestCase
{
    use CoordinatesDatabaseWorkers, ResetsCommittedFixtures;

    /** @return array<string, array{bool}> */
    public static function outcomes(): array
    {
        return ['successful scenario' => [false], 'failed scenario' => [true]];
    }

    #[DataProvider('outcomes')]
    public function test_committed_worker_fixture_survives_parent_rollback_but_not_cleanup(bool $fail): void
    {
        $database = tempnam(sys_get_temp_dir(), 'zavsync-isolation-');
        $this->assertIsString($database);
        $original = config('database.default');
        $migrated = RefreshDatabaseState::$migrated;
        config(['database.default' => 'isolation_probe', 'database.connections.isolation_probe' => [
            'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        try {
            $this->resetCommittedFixtures('isolation_probe');
            $worker = $this->startDatabaseWorker(static function ($channel): array {
                $invoice = Invoice::factory()->create();

                return ['id' => $invoice->id];
            });
            $this->signalWorker($worker, 'go');
            $fixture = $this->finishWorker($worker);
            RefreshDatabaseState::$migrated = true;
            DB::beginTransaction();
            $this->assertDatabaseHas('invoices', ['id' => $fixture['id']]);
            DB::rollBack();
            $this->assertDatabaseCount('invoices', 1);

            try {
                try {
                    if ($fail) {
                        throw new \RuntimeException('Synthetic scenario failure');
                    }
                } finally {
                    $this->stopDatabaseWorkers();
                    $this->resetCommittedFixtures('isolation_probe');
                }
            } catch (\RuntimeException $exception) {
                $this->assertSame('Synthetic scenario failure', $exception->getMessage());
            }
            $this->assertFalse(RefreshDatabaseState::$migrated);
            $this->assertDatabaseCount('invoices', 0);
            $this->assertDatabaseCount('companies', 0);
        } finally {
            $this->stopDatabaseWorkers();
            DB::purge('isolation_probe');
            config(['database.default' => $original]);
            RefreshDatabaseState::$migrated = $migrated;
            unlink($database);
        }
    }
}
