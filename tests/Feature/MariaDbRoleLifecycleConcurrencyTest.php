<?php

namespace Tests\Feature;

use App\Exceptions\PlatformException;
use App\Http\Controllers\Api\V1\Platform\CompanyUserController;
use App\Http\Controllers\Api\V1\Platform\RoleController;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\InvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CoordinatesDatabaseWorkers;
use Tests\Concerns\ResetsCommittedFixtures;
use Tests\MariaDbCertification;
use Tests\TestCase;

class MariaDbRoleLifecycleConcurrencyTest extends TestCase
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

    public function test_assignment_racing_with_deletion_cannot_recreate_a_deleted_role_reference(): void
    {
        [$company, $actor, $role] = $this->context();
        $member = CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => User::factory()->create()->id, 'is_active' => true]);
        $deletion = $this->startDeletionWorker($company, $actor, $role);
        $assignment = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $role, $member): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            try {
                app(CompanyUserController::class)->updateRoles($this->request($actor, $company, 'PUT', ['role_ids' => [$role->id]]), $member);

                return ['result' => 'unexpected_success'];
            } catch (HttpException $exception) {
                return ['result' => $exception->getStatusCode()];
            }
        });
        $this->signalWorker($assignment, 'go');
        $this->awaitWorker($assignment, 'attempting');
        $this->signalWorker($deletion, 'release');

        $this->assertSame(200, $this->finishWorker($deletion)['status']);
        $this->assertSame(404, $this->finishWorker($assignment)['result']);
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $this->assertDatabaseMissing('company_user_role', ['role_id' => $role->id]);
        $this->assertNull($member->fresh()->role_id);
    }

    public function test_invitation_racing_with_deletion_cannot_reference_a_deleted_role(): void
    {
        [$company, $actor, $role] = $this->context();
        $deletion = $this->startDeletionWorker($company, $actor, $role);
        $invitation = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $role): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            try {
                app(InvitationService::class)->invite($company->id, 'race-invite@example.com', [$role->id], $actor);

                return ['result' => 'unexpected_success'];
            } catch (PlatformException $exception) {
                return ['result' => $exception->errorCode];
            }
        });
        $this->signalWorker($invitation, 'go');
        $this->awaitWorker($invitation, 'attempting');
        $this->signalWorker($deletion, 'release');

        $this->assertSame(200, $this->finishWorker($deletion)['status']);
        $this->assertSame('TENANT_ACCESS_DENIED', $this->finishWorker($invitation)['result']);
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $this->assertDatabaseMissing('company_invitations', ['company_id' => $company->id, 'email' => 'race-invite@example.com']);
    }

    /** @return array{Company,User,Role} */
    private function context(): array
    {
        $company = Company::factory()->create();
        $actor = User::factory()->create();
        $admin = Role::factory()->for($company)->create(['name' => 'Admin', 'is_system' => true]);
        foreach (['platform.roles.manage', 'platform.users.manage'] as $name) {
            $admin->permissions()->attach(Permission::query()->firstOrCreate(['name' => $name]));
        }
        CompanyUser::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'role_id' => $admin->id, 'is_active' => true]);
        $role = Role::factory()->for($company)->create();

        return [$company, $actor, $role];
    }

    private function startDeletionWorker(Company $company, User $actor, Role $role): int
    {
        $worker = $this->startDatabaseWorker(function ($channel) use ($company, $actor, $role): array {
            return DB::transaction(function () use ($channel, $company, $actor, $role): array {
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');
                app(RoleController::class)->archive($this->request($actor, $company, 'POST'), $role);
                $response = app(RoleController::class)->destroy($this->request($actor, $company, 'DELETE'), $role);

                return ['status' => $response->getStatusCode()];
            });
        });
        $this->signalWorker($worker, 'go');
        $this->awaitWorker($worker, 'locked');

        return $worker;
    }

    /** @param array<string, mixed> $data */
    private function request(User $actor, Company $company, string $method, array $data = []): Request
    {
        $request = Request::create('/api/v1/platform/roles', $method, $data);
        $request->setUserResolver(fn (): User => $actor);
        $request->attributes->set('company_id', $company->id);

        return $request;
    }
}
