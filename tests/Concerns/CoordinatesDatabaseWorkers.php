<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

trait CoordinatesDatabaseWorkers
{
    /** @var array<int, array{pid:int, channel:resource}> */
    private array $databaseWorkers = [];

    /** @param \Closure(resource): array<string, mixed> $operation */
    private function startDatabaseWorker(\Closure $operation): int
    {
        foreach (array_keys(DB::getConnections()) as $connection) {
            DB::purge($connection);
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new \RuntimeException('Cannot create worker barrier.');
        }
        [$parent, $child] = $pair;
        stream_set_timeout($parent, 20);
        stream_set_timeout($child, 20);
        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($parent);
            fclose($child);
            throw new \RuntimeException('Cannot fork certification worker.');
        }
        if ($pid === 0) {
            fclose($parent);
            foreach ($this->databaseWorkers as $existing) {
                fclose($existing['channel']);
            }
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, static function (): void {
                exit(124);
            });
            pcntl_alarm(25);
            try {
                $this->writeBarrier($child, ['event' => 'ready']);
                $this->readBarrier($child, 'go');
                $result = $operation($child);
                $this->writeBarrier($child, ['event' => 'result', 'value' => $result]);
                exit(0);
            } catch (\Throwable $exception) {
                $this->writeBarrier($child, ['event' => 'error', 'class' => get_class($exception), 'code' => (string) $exception->getCode()]);
                exit(1);
            }
        }
        fclose($child);
        $id = $pid;
        $this->databaseWorkers[$id] = ['pid' => $pid, 'channel' => $parent];
        $this->readBarrier($parent, 'ready');

        return $id;
    }

    /** @param resource $channel @param array<string, mixed> $frame */
    private function writeBarrier(mixed $channel, array $frame): void
    {
        $encoded = json_encode($frame, JSON_THROW_ON_ERROR)."\n";
        if (fwrite($channel, $encoded) !== strlen($encoded)) {
            throw new \RuntimeException('Worker barrier write failed.');
        }
    }

    /** @param resource $channel @return array<string, mixed> */
    private function readBarrier(mixed $channel, string $event): array
    {
        $line = fgets($channel);
        if ($line === false) {
            throw new \RuntimeException('Worker barrier timed out or closed.');
        }
        $frame = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (($frame['event'] ?? null) !== $event) {
            throw new \RuntimeException('Unexpected worker event: '.($frame['event'] ?? 'missing').' '.($frame['class'] ?? ''));
        }

        return $frame;
    }

    private function signalWorker(int $id, string $event): void
    {
        $this->writeBarrier($this->databaseWorkers[$id]['channel'], ['event' => $event]);
    }

    private function awaitWorker(int $id, string $event): void
    {
        $this->readBarrier($this->databaseWorkers[$id]['channel'], $event);
    }

    /** @return array<string, mixed> */
    private function finishWorker(int $id): array
    {
        $worker = $this->databaseWorkers[$id];
        $frame = $this->readBarrier($worker['channel'], 'result');
        pcntl_waitpid($worker['pid'], $status);
        fclose($worker['channel']);
        unset($this->databaseWorkers[$id]);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));

        return $frame['value'];
    }

    private function stopDatabaseWorkers(): void
    {
        foreach ($this->databaseWorkers as $worker) {
            posix_kill($worker['pid'], SIGKILL);
            pcntl_waitpid($worker['pid'], $status);
            fclose($worker['channel']);
        }
        $this->databaseWorkers = [];
    }
}
