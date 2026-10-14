<?php

namespace Tests\Feature\Stage16;

use App\Exceptions\PlatformException;
use App\Models\AiUsageRecord;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Ai\DatabaseAiUsageMeter;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CoordinatesDatabaseWorkers;
use Tests\Concerns\ResetsCommittedFixtures;
use Tests\MariaDbCertification;
use Tests\TestCase;

class MariaDbAiQuotaConcurrencyTest extends TestCase
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

    public function test_competing_ai_reservations_cannot_both_cross_monthly_allowance(): void
    {
        $company = Company::factory()->create(['billing_policy_applies' => true]);
        $plan = Plan::factory()->create(['code' => 'starter']);
        Subscription::factory()->for($company)->for($plan)->create([
            'status' => 'TRIALING', 'trial_ends_at' => now()->addDays(7),
        ]);
        AiUsageRecord::factory()->for($company)->create([
            'input_tokens' => 47_000, 'output_tokens' => 0, 'occurred_at' => now(),
        ]);
        $first = $this->startDatabaseWorker(function ($channel) use ($company): array {
            return DB::transaction(function () use ($channel, $company): array {
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $this->writeBarrier($channel, ['event' => 'locked']);
                $this->readBarrier($channel, 'release');

                return ['id' => app(DatabaseAiUsageMeter::class)->reserveEmbedding(
                    $company->id, 'knowledge_embed', ['Concurrent quota request'],
                )];
            });
        });
        $this->signalWorker($first, 'go');
        $this->awaitWorker($first, 'locked');

        $second = $this->startDatabaseWorker(function ($channel) use ($company): array {
            $this->writeBarrier($channel, ['event' => 'attempting']);
            try {
                app(DatabaseAiUsageMeter::class)->reserveEmbedding(
                    $company->id, 'knowledge_embed', ['Concurrent quota request'],
                );

                return ['result' => 'unexpected_success'];
            } catch (PlatformException $exception) {
                return ['result' => $exception->errorCode];
            }
        });
        $this->signalWorker($second, 'go');
        $this->awaitWorker($second, 'attempting');
        $this->signalWorker($first, 'release');

        $reservation = $this->finishWorker($first);
        $loser = $this->finishWorker($second);
        $this->assertIsString($reservation['id']);
        $this->assertSame('SUBSCRIPTION_LIMIT_REACHED', $loser['result']);
        $this->assertDatabaseCount('ai_usage_reservations', 1);
        $this->assertDatabaseHas('ai_usage_reservations', [
            'id' => $reservation['id'], 'company_id' => $company->id, 'status' => 'PENDING',
        ]);
    }
}
