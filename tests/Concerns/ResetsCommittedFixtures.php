<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\MariaDbCertification;

trait ResetsCommittedFixtures
{
    /** Call only after workers are reaped and scenario assertions have completed. */
    private function resetCommittedFixtures(string $connection): void
    {
        if (config("database.connections.{$connection}.driver") !== 'sqlite') {
            MariaDbCertification::guard($this->app);
        }
        RefreshDatabaseState::$migrated = false;
        DB::purge($connection);
        $this->artisan('migrate:fresh', ['--database' => $connection, '--no-interaction' => true])->assertSuccessful();
        DB::purge($connection);
    }
}
