<?php

namespace Tests\Feature\Stage16;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Api\V1\EmployeeScheduleController;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeRota;
use App\Models\EmployeeRotaSlot;
use App\Models\EmployeeShift;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CoordinatesDatabaseWorkers;
use Tests\Concerns\ResetsCommittedFixtures;
use Tests\MariaDbCertification;
use Tests\TestCase;

class MariaDbEmployeeScheduleConcurrencyTest extends TestCase
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

    public function test_competing_assignments_for_same_employee_and_time_commit_once(): void
    {
        [$company, $actor, $employee, $slotOne, $slotTwo] = $this->context();
        $first = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $employee, $slotOne): array {
            return DB::transaction(function () use ($channel, $company, $actor, $employee, $slotOne): array {
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');

                $response = app(EmployeeScheduleController::class)->assign($this->request($company, $actor, $employee, 'concurrent-assignment-a'), $slotOne->id);

                return ['status' => $response->getStatusCode()];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $employee, $slotTwo): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            try {
                app(EmployeeScheduleController::class)->assign($this->request($company, $actor, $employee, 'concurrent-assignment-b'), $slotTwo->id);

                return ['result' => 'unexpected_success'];
            } catch (PlatformException $exception) {
                return ['result' => $exception->errorCode];
            }
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');
        $this->assertSame(201, $this->finishWorker($first)['status']);
        $this->assertSame('SCHEDULE_ASSIGNMENT_CONFLICT', $this->finishWorker($second)['result']);
        $this->assertDatabaseCount('employee_shift_assignments', 1);
        $this->assertDatabaseCount('attendance_sessions', 0);
        $this->assertDatabaseCount('journals', 0);
    }

    /** @return array{Company, User, Employee, EmployeeRotaSlot, EmployeeRotaSlot} */
    private function context(): array
    {
        $company = Company::factory()->create();
        $actor = User::factory()->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Schedule administrator']);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'schedules.manage']));
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $actor->id,
            'role_id' => $role->id, 'is_active' => true]);
        $employee = Employee::factory()->for($company)->create();
        $date = now($company->timezone)->addDays(7)->toDateString();
        $rota = EmployeeRota::factory()->create(['company_id' => $company->id, 'start_date' => $date, 'end_date' => $date]);
        $firstShift = EmployeeShift::factory()->create(['company_id' => $company->id, 'start_time' => '09:00', 'end_time' => '17:00']);
        $secondShift = EmployeeShift::factory()->create(['company_id' => $company->id, 'start_time' => '12:00', 'end_time' => '18:00']);
        $slotOne = EmployeeRotaSlot::factory()->create(['company_id' => $company->id,
            'employee_rota_id' => $rota->id, 'employee_shift_id' => $firstShift->id, 'shift_date' => $date,
            'start_time' => '09:00', 'end_time' => '17:00']);
        $slotTwo = EmployeeRotaSlot::factory()->create(['company_id' => $company->id,
            'employee_rota_id' => $rota->id, 'employee_shift_id' => $secondShift->id, 'shift_date' => $date,
            'start_time' => '12:00', 'end_time' => '18:00']);

        return [$company, $actor, $employee, $slotOne, $slotTwo];
    }

    private function request(Company $company, User $actor, Employee $employee, string $key): Request
    {
        $request = Request::create('/api/v1/hrm/rota-slots/assignments', 'POST', ['employee_id' => $employee->id]);
        $request->headers->set('Idempotency-Key', $key);
        $request->attributes->set('company_id', $company->id);
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }
}
