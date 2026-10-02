<?php

namespace Tests\Unit;

use Tests\Concerns\CoordinatesDatabaseWorkers;
use Tests\TestCase;

class DatabaseWorkerCoordinatorTest extends TestCase
{
    use CoordinatesDatabaseWorkers;

    protected function tearDown(): void
    {
        $this->stopDatabaseWorkers();
        parent::tearDown();
    }

    public function test_workers_overlap_on_explicit_barriers_and_are_reaped(): void
    {
        $parent = getmypid();
        $workers = [];
        for ($index = 0; $index < 2; $index++) {
            $workers[] = $this->startDatabaseWorker(function ($channel): array {
                $this->writeBarrier($channel, ['event' => 'waiting']);
                $this->readBarrier($channel, 'finish');

                return ['pid' => getmypid()];
            });
        }
        foreach ($workers as $worker) {
            $this->signalWorker($worker, 'go');
        }
        foreach ($workers as $worker) {
            $this->awaitWorker($worker, 'waiting');
        }
        foreach ($workers as $worker) {
            $this->signalWorker($worker, 'finish');
        }
        $pids = array_map(fn (int $worker): int => $this->finishWorker($worker)['pid'], $workers);
        $this->assertCount(2, array_unique($pids));
        $this->assertNotContains($parent, $pids);
        $this->assertSame([], $this->databaseWorkers);
    }

    public function test_worker_errors_do_not_expose_exception_messages(): void
    {
        $worker = $this->startDatabaseWorker(static function ($channel): array {
            throw new \RuntimeException('synthetic-secret-do-not-expose');
        });
        $this->signalWorker($worker, 'go');
        try {
            $this->finishWorker($worker);
            $this->fail('Worker failure must reach the parent.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('RuntimeException', $exception->getMessage());
            $this->assertStringNotContainsString('synthetic-secret', $exception->getMessage());
        }
    }

    public function test_stopped_worker_is_reaped_without_hanging(): void
    {
        $worker = $this->startDatabaseWorker(static fn ($channel): array => []);
        $pid = $this->databaseWorkers[$worker]['pid'];
        $this->stopDatabaseWorkers();
        $this->assertSame(-1, pcntl_waitpid($pid, $status, WNOHANG));
        $this->assertSame([], $this->databaseWorkers);
    }
}
