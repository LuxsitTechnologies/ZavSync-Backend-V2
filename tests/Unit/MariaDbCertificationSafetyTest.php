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
}
