<?php

namespace Tests\Feature\Stage16;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Journal;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use App\Models\Permission;
use App\Services\Payroll\PayrollEntryReleaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CoordinatesDatabaseWorkers;
use Tests\Concerns\ResetsCommittedFixtures;
use Tests\MariaDbCertification;
use Tests\TestCase;

class MariaDbPayrollReleaseConcurrencyTest extends TestCase
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

    public function test_competing_release_requests_publish_once_and_keep_first_audit_authoritative(): void
    {
        $context = $this->stage8PayrollContext();
        $company = $context['company'];
        $user = $context['user'];
        $membership = CompanyUser::query()->where('company_id', $company->id)->where('user_id', $user->id)->firstOrFail();
        $membership->role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'payroll.release']));
        $batchId = $this->postJson('/api/v1/payroll/batches', ['payroll_period_id' => $context['payrollPeriod']->id], $this->headers($company))->assertCreated()->json('id');
        foreach (['calculate', 'review', 'approve'] as $action) {
            $this->postJson("/api/v1/payroll/batches/{$batchId}/{$action}", [], $this->headers($company))->assertOk();
        }
        $this->postJson("/api/v1/payroll/batches/{$batchId}/post", [], $this->headers($company, 'post-'.$batchId))->assertOk();
        $entry = PayrollEntry::query()->where('payroll_batch_id', $batchId)->firstOrFail();
        $journalCount = Journal::query()->count();

        $first = $this->startDatabaseWorker(function ($channel) use ($company, $user, $entry): array {
            return DB::transaction(function () use ($channel, $company, $user, $entry): array {
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');
                $request = Request::create('/api/v1/payroll/entries/'.$entry->id.'/release', 'POST');
                $request->setUserResolver(fn () => $user);
                $released = app(PayrollEntryReleaseService::class)->release($request, $company->id, $entry->id);

                return ['released_at' => $released->released_at->toIso8601String()];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $user, $entry): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            $request = Request::create('/api/v1/payroll/entries/'.$entry->id.'/release', 'POST');
            $request->setUserResolver(fn () => $user);
            $released = app(PayrollEntryReleaseService::class)->release($request, $company->id, $entry->id);

            return ['released_at' => $released->released_at->toIso8601String()];
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');

        $this->assertSame($this->finishWorker($first)['released_at'], $this->finishWorker($second)['released_at']);
        $this->assertSame(1, AuditLog::query()->where('action', 'employee_payslip_released')->where('entity_id', $entry->id)->count());
        $this->assertNotNull($entry->fresh()->released_at);
        $this->assertSame($user->id, $entry->fresh()->released_by);
        $this->assertSame($journalCount, Journal::query()->count());
        $this->assertSame('POSTED', PayrollBatch::query()->findOrFail($batchId)->status);
        $this->assertDatabaseCount('payroll_payments', 0);
        $this->assertDatabaseCount('bank_transactions', 0);
    }

    /** @return array<string, string> */
    private function headers(Company $company, ?string $key = null): array
    {
        return array_filter(['X-Company-Id' => $company->id, 'Accept' => 'application/json', 'Idempotency-Key' => $key]);
    }
}
