<?php

namespace Tests\Feature\Stage16;

use App\Http\Controllers\Api\V1\EmployeeAssetRequestController;
use App\Http\Controllers\Api\V1\EmployeeExpenseClaimController;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeExpenseCategory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CoordinatesDatabaseWorkers;
use Tests\Concerns\ResetsCommittedFixtures;
use Tests\MariaDbCertification;
use Tests\TestCase;

class MariaDbEmployeePortalConcurrencyTest extends TestCase
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

    public function test_duplicate_asset_request_creates_one_authoritative_request_and_event(): void
    {
        [$company, $actor] = $this->context();
        $payload = ['type' => 'NEW_EQUIPMENT', 'item_description' => 'Work laptop', 'reason' => 'Needed for duties'];
        $this->assertConcurrentReplay($company, $actor, EmployeeAssetRequestController::class, $payload, 'same-asset-key');
        $this->assertDatabaseCount('employee_asset_requests', 1);
        $this->assertDatabaseCount('employee_asset_request_events', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_duplicate_expense_claim_creates_one_authoritative_claim_and_event(): void
    {
        [$company, $actor] = $this->context();
        $category = EmployeeExpenseCategory::factory()->create(['company_id' => $company->id]);
        $payload = ['category_id' => $category->id, 'title' => 'Travel reimbursement',
            'amount_minor' => 12500, 'expense_date' => now()->subDay()->toDateString()];
        $this->assertConcurrentReplay($company, $actor, EmployeeExpenseClaimController::class, $payload, 'same-expense-key');
        $this->assertDatabaseCount('employee_expense_claims', 1);
        $this->assertDatabaseCount('employee_expense_claim_events', 1);
        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('bank_transactions', 0);
    }

    /** @param class-string $controller @param array<string, mixed> $payload */
    private function assertConcurrentReplay(Company $company, User $actor, string $controller, array $payload, string $key): void
    {
        $first = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $controller, $payload, $key): array {
            return DB::transaction(function () use ($channel, $company, $actor, $controller, $payload, $key): array {
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');
                $response = app($controller)->store($this->request($company, $actor, $payload, $key));

                return ['id' => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'],
                    'status' => $response->getStatusCode()];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');
        $second = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $controller, $payload, $key): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            $response = app($controller)->store($this->request($company, $actor, $payload, $key));

            return ['id' => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'],
                'status' => $response->getStatusCode()];
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');
        $firstResult = $this->finishWorker($first);
        $secondResult = $this->finishWorker($second);
        $this->assertSame(201, $firstResult['status']);
        $this->assertSame(200, $secondResult['status']);
        $this->assertSame($firstResult['id'], $secondResult['id']);
    }

    /** @return array{Company, User} */
    private function context(): array
    {
        $company = Company::factory()->create();
        $actor = User::factory()->create();
        $employee = Employee::factory()->for($company)->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Employee self-service']);
        foreach (['employee.assets.request', 'employee.expenses.create'] as $permissionName) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permissionName]));
        }
        $membership = CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $actor->id,
            'role_id' => $role->id, 'is_active' => true]);
        $membership->forceFill(['employee_id' => $employee->id])->save();

        return [$company, $actor];
    }

    /** @param array<string, mixed> $payload */
    private function request(Company $company, User $actor, array $payload, string $key): Request
    {
        $request = Request::create('/api/v1/employee/request', 'POST', $payload);
        $request->headers->set('Idempotency-Key', $key);
        $request->attributes->set('company_id', $company->id);
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }
}
