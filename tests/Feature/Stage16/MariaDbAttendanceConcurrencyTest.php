<?php

namespace Tests\Feature\Stage16;

use App\Exceptions\PlatformException;
use App\Models\AttendanceBreak;
use App\Models\AttendanceSession;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Journal;
use App\Models\PayrollEntry;
use App\Models\User;
use App\Services\Attendance\AttendanceCorrectionService;
use App\Services\Attendance\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CoordinatesDatabaseWorkers;
use Tests\Concerns\ResetsCommittedFixtures;
use Tests\MariaDbCertification;
use Tests\TestCase;

class MariaDbAttendanceConcurrencyTest extends TestCase
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

    public function test_competing_clock_ins_leave_one_active_session_and_no_financial_effects(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->create();
        $employee = Employee::factory()->for($company)->create(['created_by' => $actor->id]);
        $first = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $employee): array {
            return DB::transaction(function () use ($channel, $company, $actor, $employee): array {
                Employee::query()->where('company_id', $company->id)->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');
                $result = app(AttendanceService::class)->transition($this->request($actor), $company->id, $employee->id, 'CLOCK_IN', 'worker-a');

                return ['id' => $result['id']];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $employee): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            try {
                app(AttendanceService::class)->transition($this->request($actor), $company->id, $employee->id, 'CLOCK_IN', 'worker-b');

                return ['state' => 'unexpected_success'];
            } catch (PlatformException $exception) {
                return ['state' => $exception->errorCode];
            }
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');

        $firstResult = $this->finishWorker($first);
        $this->assertSame(AttendanceSession::query()->sole()->id, $firstResult['id']);
        $this->assertSame('ATTENDANCE_ALREADY_CLOCKED_IN', $this->finishWorker($second)['state']);
        $this->assertSame(1, AttendanceSession::query()->where('active_employee_id', $employee->id)->count());
        $this->assertSame(0, PayrollEntry::query()->count());
        $this->assertSame(0, Journal::query()->count());
    }

    public function test_competing_correction_decisions_create_only_one_revision(): void
    {
        $company = Company::factory()->create();
        $actor = User::factory()->create();
        $employee = Employee::factory()->for($company)->create(['created_by' => $actor->id]);
        $session = AttendanceSession::factory()->for($company)->for($employee)->create(['created_by' => $actor->id]);
        $request = $this->request($actor);
        $correction = app(AttendanceCorrectionService::class)->submit($request, $company->id, $employee->id, $session->id, [
            'clock_in_at' => '2026-09-01T04:00:00Z', 'clock_out_at' => '2026-09-01T13:00:00Z',
            'breaks' => [], 'reason' => 'Corrected after manager review.',
        ], 'correction-a');
        $first = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $employee, $correction): array {
            return DB::transaction(function () use ($channel, $company, $actor, $employee, $correction): array {
                Employee::query()->where('company_id', $company->id)->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');
                $result = app(AttendanceCorrectionService::class)->decide($this->request($actor), $company->id, $correction->id, true, 'Approved evidence.');

                return ['status' => $result->status];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $correction): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            try {
                app(AttendanceCorrectionService::class)->decide($this->request($actor), $company->id, $correction->id, false, 'Opposite decision.');

                return ['status' => 'unexpected_success'];
            } catch (PlatformException $exception) {
                return ['status' => $exception->errorCode];
            }
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');

        $this->assertSame('APPROVED', $this->finishWorker($first)['status']);
        $this->assertSame('ATTENDANCE_CORRECTION_DECIDED', $this->finishWorker($second)['status']);
        $this->assertDatabaseCount('attendance_revisions', 1);
        $this->assertSame(28800, $session->fresh()->worked_seconds);
        $this->assertSame(0, PayrollEntry::query()->count());
        $this->assertSame(0, Journal::query()->count());
    }

    public function test_competing_clock_outs_close_the_session_once(): void
    {
        [$company, $actor, $employee] = $this->attendanceContext();
        $session = AttendanceSession::factory()->for($company)->for($employee)->open()->create(['created_by' => $actor->id]);

        $this->assertCompetingTransition($company, $actor, $employee, 'CLOCK_OUT', 'ATTENDANCE_INVALID_TRANSITION');

        $this->assertSame('CLOCKED_OUT', $session->fresh()->state);
        $this->assertNull($session->fresh()->active_employee_id);
        $this->assertSame(1, AttendanceSession::query()->count());
        $this->assertSame(0, PayrollEntry::query()->count());
        $this->assertSame(0, Journal::query()->count());
    }

    public function test_competing_break_starts_create_only_one_open_break(): void
    {
        [$company, $actor, $employee] = $this->attendanceContext();
        $session = AttendanceSession::factory()->for($company)->for($employee)->open()->create(['created_by' => $actor->id]);

        $this->assertCompetingTransition($company, $actor, $employee, 'BREAK_START', 'ATTENDANCE_INVALID_TRANSITION');

        $this->assertSame('ON_BREAK', $session->fresh()->state);
        $this->assertSame(1, AttendanceBreak::query()->where('active_session_id', $session->id)->count());
        $this->assertSame(0, PayrollEntry::query()->count());
        $this->assertSame(0, Journal::query()->count());
    }

    public function test_competing_break_ends_close_the_break_once(): void
    {
        [$company, $actor, $employee] = $this->attendanceContext();
        $session = AttendanceSession::factory()->for($company)->for($employee)->open()->create([
            'created_by' => $actor->id, 'state' => 'ON_BREAK',
        ]);
        $break = AttendanceBreak::factory()->for($session, 'session')->create([
            'company_id' => $company->id, 'started_at' => now('UTC')->subMinutes(5),
            'ended_at' => null, 'duration_seconds' => null, 'active_session_id' => $session->id,
        ]);

        $this->assertCompetingTransition($company, $actor, $employee, 'BREAK_END', 'ATTENDANCE_INVALID_TRANSITION');

        $this->assertSame('CLOCKED_IN', $session->fresh()->state);
        $this->assertNull($break->fresh()->active_session_id);
        $this->assertSame(1, AttendanceBreak::query()->count());
        $this->assertSame(0, PayrollEntry::query()->count());
        $this->assertSame(0, Journal::query()->count());
    }

    /** @return array{Company, User, Employee} */
    private function attendanceContext(): array
    {
        $company = Company::factory()->create();
        $actor = User::factory()->create();
        $employee = Employee::factory()->for($company)->create(['created_by' => $actor->id]);

        return [$company, $actor, $employee];
    }

    private function assertCompetingTransition(Company $company, User $actor, Employee $employee, string $action, string $expectedConflict): void
    {
        $first = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $employee, $action): array {
            return DB::transaction(function () use ($channel, $company, $actor, $employee, $action): array {
                Employee::query()->where('company_id', $company->id)->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');
                $result = app(AttendanceService::class)->transition($this->request($actor), $company->id, $employee->id, $action, 'worker-a');

                return ['state' => $result['state']];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $employee, $action): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            try {
                app(AttendanceService::class)->transition($this->request($actor), $company->id, $employee->id, $action, 'worker-b');

                return ['state' => 'unexpected_success'];
            } catch (PlatformException $exception) {
                return ['state' => $exception->errorCode];
            }
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');

        $this->assertNotSame('unexpected_success', $this->finishWorker($first)['state']);
        $this->assertSame($expectedConflict, $this->finishWorker($second)['state']);
    }

    private function request(User $actor): Request
    {
        $request = Request::create('/api/v1/employee/attendance', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }
}
