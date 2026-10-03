<?php

namespace Tests\Feature\Stage16;

use App\Exceptions\PlatformException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Journal;
use App\Models\LeaveEntitlement;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestEvent;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CoordinatesDatabaseWorkers;
use Tests\Concerns\ResetsCommittedFixtures;
use Tests\MariaDbCertification;
use Tests\TestCase;

class MariaDbLeaveConcurrencyTest extends TestCase
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

    public function test_competing_requests_for_same_slot_reserve_once(): void
    {
        [$company, $actor, $employee, $type] = $this->context();
        $data = ['leave_type_id' => $type->id, 'start_date' => '2099-10-05', 'end_date' => '2099-10-05',
            'day_portion' => 'FULL_DAY', 'reason' => 'Concurrent annual leave'];
        $first = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $employee, $data): array {
            return DB::transaction(function () use ($channel, $company, $actor, $employee, $data): array {
                Employee::query()->where('company_id', $company->id)->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');
                $item = app(LeaveService::class)->submit($this->request($actor), $company->id, $employee->id, $data, 'worker-a');

                return ['id' => $item->id];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $employee, $data): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            try {
                app(LeaveService::class)->submit($this->request($actor), $company->id, $employee->id, $data, 'worker-b');

                return ['result' => 'unexpected_success'];
            } catch (PlatformException $exception) {
                return ['result' => $exception->errorCode];
            }
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');
        $this->assertSame(LeaveRequest::query()->sole()->id, $this->finishWorker($first)['id']);
        $this->assertSame('LEAVE_OVERLAP', $this->finishWorker($second)['result']);
        $this->assertSame(2, app(LeaveService::class)->balance(LeaveEntitlement::query()->sole())['pending_units']);
        $this->assertSame(0, Journal::query()->count());
    }

    public function test_rolled_back_reservation_does_not_consume_entitlement(): void
    {
        [$company, $actor, $employee, $type] = $this->context();
        $data = ['leave_type_id' => $type->id, 'start_date' => '2099-10-05', 'end_date' => '2099-10-05',
            'day_portion' => 'FULL_DAY', 'reason' => 'Rollback leave request'];
        try {
            DB::transaction(function () use ($company, $actor, $employee, $data): void {
                app(LeaveService::class)->submit($this->request($actor), $company->id, $employee->id, $data, 'rolled-back');
                throw new \RuntimeException('Intentional rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Intentional rollback', $exception->getMessage());
        }
        $this->assertSame(0, LeaveRequest::query()->count());
        $this->assertSame(40, app(LeaveService::class)->balance(LeaveEntitlement::query()->sole())['available_units']);
        $this->assertSame(0, Journal::query()->count());
    }

    public function test_competing_approval_and_rejection_commit_only_one_decision(): void
    {
        [$company, $actor, $employee, $type] = $this->context();
        $data = ['leave_type_id' => $type->id, 'start_date' => '2099-10-05', 'end_date' => '2099-10-05',
            'day_portion' => 'FULL_DAY', 'reason' => 'Concurrent approval review'];
        $leave = app(LeaveService::class)->submit($this->request($actor), $company->id, $employee->id, $data, 'decision');
        $first = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $employee, $leave): array {
            return DB::transaction(function () use ($channel, $company, $actor, $employee, $leave): array {
                Employee::query()->where('company_id', $company->id)->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');

                return ['status' => app(LeaveService::class)->transition($this->request($actor), $company->id, $leave->id, 'APPROVE')->status];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $leave): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            try {
                app(LeaveService::class)->transition($this->request($actor), $company->id, $leave->id, 'REJECT', 'Conflicting decision');

                return ['status' => 'unexpected_success'];
            } catch (PlatformException $exception) {
                return ['status' => $exception->errorCode];
            }
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');
        $this->assertSame('APPROVED', $this->finishWorker($first)['status']);
        $this->assertSame('LEAVE_ALREADY_DECIDED', $this->finishWorker($second)['status']);
        $this->assertSame(2, LeaveRequestEvent::query()->count());
        $this->assertSame(2, app(LeaveService::class)->balance(LeaveEntitlement::query()->sole())['approved_units']);
        $this->assertSame(0, Journal::query()->count());
    }

    /** @return array{Company, User, Employee, LeaveType} */
    private function context(): array
    {
        $company = Company::factory()->create();
        $actor = User::factory()->create();
        $employee = Employee::factory()->for($company)->create(['created_by' => $actor->id]);
        $type = LeaveType::factory()->for($company)->create(['name' => 'Annual Leave', 'is_paid' => true]);
        LeaveEntitlement::factory()->for($company)->for($employee)->for($type, 'type')->create(['year' => 2099, 'allocated_units' => 40, 'created_by' => $actor->id]);

        return [$company, $actor, $employee, $type];
    }

    private function request(User $actor): Request
    {
        $request = Request::create('/api/v1/employee/leave/requests', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }
}
