<?php

namespace Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use RuntimeException;

/** Child-PHPUnit fixture: real Laravel handler lifecycle, fake connectivity only. */
class MariaDbHandlerProbeTest extends TestCase
{
    public function createApplication(): Application
    {
        self::fakeConnectivity();

        return parent::createApplication();
    }

    public static function fakeConnectivity(): void
    {
        Connection::resolverFor('mysql', function ($pdo, $database, $prefix, $config): MySqlConnection {
            return new class(null, $database, $prefix, $config) extends MySqlConnection
            {
                public function getPdo(): never
                {
                    throw new RuntimeException('Network access is forbidden in the handler fixture.');
                }

                public function selectOne($query, $bindings = [], $useReadPdo = true): object
                {
                    if ($query !== 'SELECT DATABASE() AS database_name, VERSION() AS server_version') {
                        throw new RuntimeException('Unexpected query in the handler fixture.');
                    }

                    return (object) ['database_name' => 'zavsync_v2_stage15_cert', 'server_version' => '11.8.9-MariaDB-ubu2404'];
                }
            };
        });

    }

    public function test_first_application_uses_normal_handler_lifecycle(): void
    {
        $this->assertTrue($this->app->bound('files'));
        $this->assertTrue($this->app->hasBeenBootstrapped());
    }

    public function test_next_application_uses_normal_handler_lifecycle(): void
    {
        $this->assertTrue($this->app->bound('files'));
        $this->assertTrue($this->app->hasBeenBootstrapped());
    }
}
