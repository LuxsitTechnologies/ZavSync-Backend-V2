<?php

namespace Tests\Feature\Stage16;

use App\Exceptions\PlatformException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeTask;
use App\Models\EmployeeTicket;
use App\Models\User;
use App\Services\Work\EmployeeWorkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CoordinatesDatabaseWorkers;
use Tests\Concerns\ResetsCommittedFixtures;
use Tests\MariaDbCertification;
use Tests\TestCase;

class MariaDbEmployeeWorkConcurrencyTest extends TestCase
{
    use CoordinatesDatabaseWorkers, ResetsCommittedFixtures;

    private bool $databaseWasReset = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('ZAVSYNC_MARIADB_CERTIFICATION') || getenv('MARIADB_CERTIFICATION_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Requires guarded manual MariaDB concurrency mode.');
        }
        $this->assertTrue(function_exists('pcntl_fork') && function_exists('posix_kill') && function_exists('stream_socket_pair'));
        MariaDbCertification::guard($this->app);
        $this->databaseWasReset = true;
        $this->artisan('migrate:fresh', ['--database' => 'mysql', '--no-interaction' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        $this->stopDatabaseWorkers();
        try {
            if ($this->databaseWasReset) {
                $this->resetCommittedFixtures('mysql');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_duplicate_ticket_create_returns_only_one_authoritative_result(): void
    {
        [$company, $actor, $employee] = $this->context();
        $data = ['subject' => 'Concurrent request', 'description' => 'Please help with this.', 'priority' => 'NORMAL'];
        $first = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $employee, $data): array {
            return DB::transaction(function () use ($channel, $company, $actor, $employee, $data): array {
                Employee::query()->where('company_id', $company->id)->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');

                return ['id' => app(EmployeeWorkService::class)->createTicket($this->request($actor), $company->id, $employee->id, $data, 'same-ticket-key')->id];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $employee, $data): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);

            return ['id' => app(EmployeeWorkService::class)->createTicket($this->request($actor), $company->id, $employee->id, $data, 'same-ticket-key')->id];
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');
        $this->assertSame($this->finishWorker($first)['id'], $this->finishWorker($second)['id']);
        $this->assertDatabaseCount('employee_tickets', 1);
        $this->assertDatabaseCount('employee_ticket_events', 1);
    }

    public function test_simultaneous_task_transition_rejects_stale_version(): void
    {
        [$company, $actor, $employee] = $this->context();
        $task = EmployeeTask::factory()->create(['company_id' => $company->id, 'assigned_employee_id' => $employee->id, 'created_by' => $actor->id]);
        $first = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $task): array {
            return DB::transaction(function () use ($channel, $company, $actor, $task): array {
                EmployeeTask::query()->where('company_id', $company->id)->whereKey($task->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');

                return ['status' => app(EmployeeWorkService::class)->transitionTask($this->request($actor), $company->id, $task->id, 'START', 1)->status];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $task): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            try {
                app(EmployeeWorkService::class)->transitionTask($this->request($actor), $company->id, $task->id, 'START', 1);

                return ['result' => 'unexpected_success'];
            } catch (PlatformException $exception) {
                return ['result' => $exception->errorCode];
            }
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');
        $this->assertSame('IN_PROGRESS', $this->finishWorker($first)['status']);
        $this->assertSame('WORK_VERSION_STALE', $this->finishWorker($second)['result']);
        $this->assertDatabaseCount('employee_task_events', 1);
    }

    public function test_ticket_close_wins_over_late_comment_and_rollback_preserves_state(): void
    {
        [$company, $actor, $employee] = $this->context();
        $ticket = EmployeeTicket::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'created_by' => $actor->id]);
        $first = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $ticket): array {
            return DB::transaction(function () use ($channel, $company, $actor, $ticket): array {
                EmployeeTicket::query()->where('company_id', $company->id)->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');

                return ['status' => app(EmployeeWorkService::class)->transitionTicket($this->request($actor), $company->id, $ticket->id, 'CLOSE', 1)->status];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $ticket): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            try {
                app(EmployeeWorkService::class)->commentTicket($this->request($actor), $company->id, $ticket->id, 'Too late', 'late-comment');

                return ['result' => 'unexpected_success'];
            } catch (PlatformException $exception) {
                return ['result' => $exception->errorCode];
            }
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');
        $this->assertSame('CLOSED', $this->finishWorker($first)['status']);
        $this->assertSame('TICKET_COMMENT_CLOSED', $this->finishWorker($second)['result']);
        $this->assertDatabaseCount('employee_ticket_comments', 0);
        try {
            DB::transaction(function () use ($company, $actor, $ticket): void {
                app(EmployeeWorkService::class)->transitionTicket($this->request($actor), $company->id, $ticket->id, 'REOPEN', 2);
                throw new \RuntimeException('Intentional rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Intentional rollback', $exception->getMessage());
        }
        $this->assertSame('CLOSED', $ticket->fresh()->status);
        $this->assertDatabaseCount('employee_ticket_events', 1);
    }

    public function test_different_ticket_keys_commit_independently_and_reused_key_rejects_changed_payload(): void
    {
        [$company, $actor, $employee] = $this->context();
        $data = ['subject' => 'First request', 'description' => 'Please assist with this.', 'priority' => 'NORMAL'];
        $first = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $employee, $data): array {
            return DB::transaction(function () use ($channel, $company, $actor, $employee, $data): array {
                Employee::query()->where('company_id', $company->id)->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');

                return ['id' => app(EmployeeWorkService::class)->createTicket($this->request($actor), $company->id, $employee->id, $data, 'different-key-a')->id];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $employee, $data): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);

            return ['id' => app(EmployeeWorkService::class)->createTicket($this->request($actor), $company->id, $employee->id, $data, 'different-key-b')->id];
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');
        $this->assertNotSame($this->finishWorker($first)['id'], $this->finishWorker($second)['id']);
        $this->assertDatabaseCount('employee_tickets', 2);
        try {
            app(EmployeeWorkService::class)->createTicket($this->request($actor), $company->id, $employee->id, [...$data, 'subject' => 'Changed'], 'different-key-a');
            $this->fail('A reused key with changed content must conflict.');
        } catch (PlatformException $exception) {
            $this->assertSame('WORK_IDEMPOTENCY_CONFLICT', $exception->errorCode);
        }
    }

    public function test_task_completion_and_reassignment_compete_with_stale_version_protection(): void
    {
        [$company, $actor, $employee] = $this->context();
        $task = EmployeeTask::factory()->create(['company_id' => $company->id, 'assigned_employee_id' => $employee->id, 'created_by' => $actor->id, 'status' => 'IN_PROGRESS']);
        $newEmployee = Employee::factory()->for($company)->create(['created_by' => $actor->id]);
        $first = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $task): array {
            return DB::transaction(function () use ($channel, $company, $actor, $task): array {
                EmployeeTask::query()->where('company_id', $company->id)->whereKey($task->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');

                return ['status' => app(EmployeeWorkService::class)->transitionTask($this->request($actor), $company->id, $task->id, 'COMPLETE', 1)->status];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $task, $newEmployee): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            try {
                app(EmployeeWorkService::class)->assignTask($this->request($actor), $company->id, $task->id, $newEmployee->id, 1);

                return ['result' => 'unexpected_success'];
            } catch (PlatformException $exception) {
                return ['result' => $exception->errorCode];
            }
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');
        $this->assertSame('COMPLETED', $this->finishWorker($first)['status']);
        $this->assertSame('WORK_VERSION_STALE', $this->finishWorker($second)['result']);
        $this->assertSame($employee->id, $task->fresh()->assigned_employee_id);
        $this->assertNotNull($task->fresh()->completed_at);
        $this->assertDatabaseCount('employee_task_events', 1);
    }

    public function test_admin_ticket_transitions_compete_and_duplicate_comments_commit_once(): void
    {
        [$company, $actor, $employee] = $this->context();
        $ticket = EmployeeTicket::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id, 'created_by' => $actor->id]);
        $first = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $ticket): array {
            return DB::transaction(function () use ($channel, $company, $actor, $ticket): array {
                EmployeeTicket::query()->where('company_id', $company->id)->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');

                return ['status' => app(EmployeeWorkService::class)->transitionTicket($this->request($actor), $company->id, $ticket->id, 'START', 1)->status];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $ticket): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            try {
                app(EmployeeWorkService::class)->transitionTicket($this->request($actor), $company->id, $ticket->id, 'CLOSE', 1);

                return ['result' => 'unexpected_success'];
            } catch (PlatformException $exception) {
                return ['result' => $exception->errorCode];
            }
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');
        $this->assertSame('IN_PROGRESS', $this->finishWorker($first)['status']);
        $this->assertSame('WORK_VERSION_STALE', $this->finishWorker($second)['result']);
        $this->assertDatabaseCount('employee_ticket_events', 1);

        $commentFirst = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $ticket): array {
            return DB::transaction(function () use ($channel, $company, $actor, $ticket): array {
                EmployeeTicket::query()->where('company_id', $company->id)->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');

                return ['id' => app(EmployeeWorkService::class)->commentTicket($this->request($actor), $company->id, $ticket->id, 'Same response', 'duplicate-comment')->id];
            });
        });
        $this->signalWorker($commentFirst, 'go');
        $this->awaitWorker($commentFirst, 'locked');
        $commentSecond = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $ticket): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);

            return ['id' => app(EmployeeWorkService::class)->commentTicket($this->request($actor), $company->id, $ticket->id, 'Same response', 'duplicate-comment')->id];
        });
        $this->signalWorker($commentSecond, 'go');
        $this->awaitWorker($commentSecond, 'attempting');
        $this->signalWorker($commentFirst, 'release');
        $this->assertSame($this->finishWorker($commentFirst)['id'], $this->finishWorker($commentSecond)['id']);
        $this->assertDatabaseCount('employee_ticket_comments', 1);
    }

    public function test_rolled_back_ticket_creation_leaves_no_ticket_or_event(): void
    {
        [$company, $actor, $employee] = $this->context();
        try {
            DB::transaction(function () use ($company, $actor, $employee): void {
                app(EmployeeWorkService::class)->createTicket($this->request($actor), $company->id, $employee->id,
                    ['subject' => 'Rollback request', 'description' => 'This transaction must roll back.', 'priority' => 'NORMAL'], 'rollback-ticket');
                throw new \RuntimeException('Intentional rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Intentional rollback', $exception->getMessage());
        }
        $this->assertDatabaseCount('employee_tickets', 0);
        $this->assertDatabaseCount('employee_ticket_events', 0);
    }

    /** @return array{Company, User, Employee} */
    private function context(): array
    {
        $company = Company::factory()->create();
        $actor = User::factory()->create();
        $employee = Employee::factory()->for($company)->create(['created_by' => $actor->id]);

        return [$company, $actor, $employee];
    }

    private function request(User $actor): Request
    {
        $request = Request::create('/api/v1/employee/tickets', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }
}
