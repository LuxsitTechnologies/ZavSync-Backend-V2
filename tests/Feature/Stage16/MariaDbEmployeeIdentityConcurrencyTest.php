<?php

namespace Tests\Feature\Stage16;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CoordinatesDatabaseWorkers;
use Tests\Concerns\ResetsCommittedFixtures;
use Tests\MariaDbCertification;
use Tests\TestCase;

class MariaDbEmployeeIdentityConcurrencyTest extends TestCase
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

    public function test_competing_memberships_cannot_claim_the_same_employee(): void
    {
        $company = Company::factory()->create();
        $administrator = User::factory()->create();
        $employee = Employee::factory()->for($company)->create(['created_by' => $administrator->id]);
        $firstMembership = CompanyUser::factory()->for($company)->create();
        $secondMembership = CompanyUser::factory()->for($company)->create();

        $first = $this->startDatabaseWorker(function ($channel) use ($company, $employee, $firstMembership): array {
            return DB::transaction(function () use ($channel, $company, $employee, $firstMembership): array {
                DB::table('companies')->where('id', $company->id)->lockForUpdate()->first();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');
                DB::table('company_users')->where('id', $firstMembership->id)->update(['employee_id' => $employee->id]);

                return ['state' => 'linked'];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $employee, $secondMembership): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            try {
                DB::transaction(function () use ($company, $employee, $secondMembership): void {
                    DB::table('companies')->where('id', $company->id)->lockForUpdate()->first();
                    DB::table('company_users')->where('id', $secondMembership->id)->update(['employee_id' => $employee->id]);
                });

                return ['state' => 'linked'];
            } catch (QueryException) {
                return ['state' => 'unique_constraint_rejected'];
            }
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');

        $this->assertSame('linked', $this->finishWorker($first)['state']);
        $this->assertSame('unique_constraint_rejected', $this->finishWorker($second)['state']);
        $this->assertSame(1, CompanyUser::query()->where('employee_id', $employee->id)->count());
        foreach (['invoices', 'pakistan_fbr_invoices', 'journals', 'journal_lines', 'customer_payments', 'inventory_movements', 'bank_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
