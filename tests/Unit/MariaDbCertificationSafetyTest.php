<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\MariaDbCertification;

class MariaDbCertificationSafetyTest extends TestCase
{
    /** @return array<string, mixed> */
    private function target(): array
    {
        return ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '33079',
            'database' => 'zavsync_v2_stage15_cert', 'username' => 'zavsync_stage15'];
    }

    public function test_accepts_only_the_disposable_target_and_expected_server(): void
    {
        MariaDbCertification::assertTarget($this->target());
        MariaDbCertification::assertServer('mysql', 'zavsync_v2_stage15_cert', '11.8.9-MariaDB-ubu2404');
        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafeTargets')]
    public function test_rejects_unsafe_target_before_connection(string $key, mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        MariaDbCertification::assertTarget([$key => $value] + $this->target());
    }

    public static function unsafeTargets(): array
    {
        return [['driver', 'sqlite'], ['host', 'remote.invalid'], ['port', '3306'],
            ['database', ':memory:'], ['database', 'other_database'], ['username', 'root'],
            ['url', 'mysql://override.invalid'], ['unix_socket', '/tmp/mysql.sock'],
            ['read', ['host' => 'remote.invalid']], ['write', ['host' => 'remote.invalid']]];
    }

    #[DataProvider('unsafeServers')]
    public function test_rejects_wrong_runtime_engine_database_or_version(string $driver, string $database, string $version): void
    {
        $this->expectException(RuntimeException::class);
        MariaDbCertification::assertServer($driver, $database, $version);
    }

    public static function unsafeServers(): array
    {
        return [['sqlite', ':memory:', '3.0'], ['mysql', 'zavsync_v2_stage15_cert', '8.0.40'],
            ['mysql', 'other_database', '11.8.9-MariaDB'], ['mysql', 'zavsync_v2_stage15_cert', '11.8.8-MariaDB']];
    }

    public function test_configuration_inherits_suites_without_sqlite_or_credentials(): void
    {
        $root = dirname(__DIR__, 2);
        $xml = MariaDbCertification::configuration($root);
        $xpath = new \DOMXPath($xml);
        $this->assertSame(0, $xpath->query('//env[starts-with(@name,"DB_")]')->length);
        $this->assertSame($root.'/tests/mariadb-bootstrap.php', $xml->documentElement->getAttribute('bootstrap'));
        $this->assertSame([$root.'/tests/Unit', $root.'/tests/Feature'],
            array_map(fn ($node): string => $node->textContent, iterator_to_array($xpath->query('//testsuite/directory'))));
        $this->assertSame('testing', $xpath->query('//env[@name="APP_ENV"]')->item(0)->getAttribute('value'));
        $this->assertSame('true', $xpath->query('//env[@name="APP_ENV"]')->item(0)->getAttribute('force'));
        $this->assertTrue($xml->schemaValidate($root.'/vendor/phpunit/phpunit/phpunit.xsd'));
    }

    public function test_runner_refuses_without_consent_before_any_database_access(): void
    {
        $process = new Process([PHP_BINARY, 'tests/mariadb.php', 'preflight'], dirname(__DIR__, 2), ['MARIADB_CERTIFICATION_RESET' => '']);
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('reset consent', $process->getErrorOutput());
    }

    /** @return array<string, string> */
    private function certificationEnvironment(): array
    {
        return ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '33079', 'DB_DATABASE' => 'zavsync_v2_stage15_cert', 'DB_USERNAME' => 'zavsync_stage15',
            'DB_PASSWORD' => 'synthetic-never-connected', 'DB_URL' => '', 'DB_SOCKET' => '',
            'MARIADB_CERTIFICATION_RESET' => 'zavsync_v2_stage15_cert', 'TEST_TOKEN' => '',
            'LARAVEL_PARALLEL_TESTING' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
    }

    #[DataProvider('unsafeProcessValues')]
    public function test_preflight_rejects_unsafe_process_values_before_bootstrap(string $key, string $value): void
    {
        $process = new Process([PHP_BINARY, 'tests/mariadb.php', 'preflight'], dirname(__DIR__, 2),
            [$key => $value] + $this->certificationEnvironment());
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('Certification', $process->getErrorOutput());
        $this->assertStringNotContainsString('synthetic-never-connected', $process->getErrorOutput());
    }

    public static function unsafeProcessValues(): array
    {
        return [['DB_CONNECTION', 'sqlite'], ['DB_DATABASE', ':memory:'], ['DB_DATABASE', 'wrong'],
            ['DB_HOST', 'remote.invalid'], ['DB_PORT', '3306'], ['DB_USERNAME', 'root'],
            ['MARIADB_CERTIFICATION_RESET', 'wrong'], ['DB_URL', 'mysql://override.invalid'],
            ['DB_SOCKET', '/tmp/mysql.sock']];
    }

    public function test_supported_bootstrap_registers_files_and_reaches_only_mocked_connectivity(): void
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = Tests\MariaDbCertification::bootApplication();
if (! $app->bound('files') || ! $app->hasBeenBootstrapped()) {
    throw new RuntimeException('Incomplete application bootstrap.');
}
$connection = Mockery::mock(Illuminate\Database\Connection::class)->makePartial();
$connection->shouldReceive('getPdo')->never();
$connection->shouldReceive('getConfig')->withNoArgs()->once()->andReturn($app['config']->get('database.connections.mysql'));
$connection->shouldReceive('getConfig')->with('name')->andReturn('mysql');
$connection->shouldReceive('selectOne')->once()->with('SELECT DATABASE() AS database_name, VERSION() AS server_version')
    ->andReturn((object) ['database_name' => 'zavsync_v2_stage15_cert', 'server_version' => '11.8.9-MariaDB-ubu2404']);
$connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
$app['db']->extend('mysql', fn () => $connection);
Tests\MariaDbCertification::guard($app);
Mockery::close();
echo 'BOOTSTRAP_AND_MOCKED_CONNECTIVITY_PASS';
PHP;
        $process = new Process([PHP_BINARY, '-r', $code], dirname(__DIR__, 2), $this->certificationEnvironment());
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertSame('BOOTSTRAP_AND_MOCKED_CONNECTIVITY_PASS', $process->getOutput());
    }

    #[DataProvider('cacheVariables')]
    public function test_preflight_rejects_cache_files_without_executing_them(string $key): void
    {
        $process = new Process([PHP_BINARY, 'tests/mariadb.php', 'preflight'], dirname(__DIR__, 2),
            [$key => __FILE__] + $this->certificationEnvironment());
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('clear generated configuration/route caches', $process->getErrorOutput().$process->getOutput());
        $this->assertStringNotContainsString('Target class [files]', $process->getErrorOutput().$process->getOutput());
    }

    public static function cacheVariables(): array
    {
        return [['APP_CONFIG_CACHE'], ['APP_ROUTES_CACHE']];
    }

    public function test_isolated_preflight_and_phpunit_bootstrap_preserve_parent_handlers(): void
    {
        $errorHandler = get_error_handler();
        $exceptionHandler = get_exception_handler();
        $code = <<<'PHP'
require 'vendor/autoload.php';
require 'tests/MariaDbHandlerProbeTest.php';
Tests\MariaDbHandlerProbeTest::fakeConnectivity();
$argv = ['tests/mariadb.php', 'preflight'];
require 'tests/mariadb.php';
PHP;
        $process = new Process([PHP_BINARY, '-r', $code], dirname(__DIR__, 2), $this->certificationEnvironment());
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('MariaDB safety assertion PASS', $process->getOutput());
        $this->assertSame($errorHandler, get_error_handler());
        $this->assertSame($exceptionHandler, get_exception_handler());

        $code = <<<'PHP'
require 'vendor/autoload.php';
$error = static function (): bool { return false; };
$exception = static function (Throwable $exception): void {};
set_error_handler($error);
set_exception_handler($exception);
require 'tests/mariadb-bootstrap.php';
if (get_error_handler() !== $error || get_exception_handler() !== $exception) {
    throw new RuntimeException('PHPUnit bootstrap modified global handlers.');
}
restore_error_handler();
restore_exception_handler();
echo 'HANDLERS_UNCHANGED';
PHP;
        $process = new Process([PHP_BINARY, '-r', $code], dirname(__DIR__, 2), $this->certificationEnvironment());
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('HANDLERS_UNCHANGED', $process->getOutput());
    }

    public function test_generated_config_runs_real_phpunit_lifecycle_without_risky_tests(): void
    {
        $root = dirname(__DIR__, 2);
        $xml = MariaDbCertification::configuration($root);
        $suites = $xml->getElementsByTagName('testsuites')->item(0);
        while ($suites->firstChild) {
            $suites->removeChild($suites->firstChild);
        }
        $suite = $xml->createElement('testsuite');
        $suite->setAttribute('name', 'Handler lifecycle probe');
        $suite->appendChild($xml->createElement('file', $root.'/tests/MariaDbHandlerProbeTest.php'));
        $suites->appendChild($suite);
        $temporary = tempnam(sys_get_temp_dir(), 'zavsync-handler-test-');
        try {
            $xml->save($temporary);
            $process = new Process([PHP_BINARY, 'vendor/bin/phpunit', '--configuration', $temporary,
                '--do-not-cache-result', '--fail-on-risky', '--fail-on-warning'], $root, $this->certificationEnvironment());
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $summary = json_decode(trim($process->getOutput()), true);
            if (is_array($summary)) {
                $this->assertSame('passed', $summary['result']);
                $this->assertSame(2, $summary['tests']);
                $this->assertSame(4, $summary['assertions']);
            } else {
                $this->assertStringContainsString('OK (2 tests, 4 assertions)', $process->getOutput());
            }
        } finally {
            unlink($temporary);
        }
    }
}
