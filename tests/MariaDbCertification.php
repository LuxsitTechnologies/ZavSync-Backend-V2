<?php

namespace Tests;

use App\Services\Outreach\SmtpEmailGateway;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MariaDbCertification
{
    public static function assertCacheFilesAbsent(Application $app): void
    {
        foreach ([$app->getCachedConfigPath(), $app->getCachedRoutesPath()] as $path) {
            if (file_exists($path)) {
                throw new RuntimeException('Certification refused: clear generated configuration/route caches in a separate step.');
            }
        }
    }

    public static function bootApplication(): Application
    {
        self::assertProcess();
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        self::assertCacheFilesAbsent($app);
        $app->beforeBootstrapping(LoadConfiguration::class, function (Application $app): void {
            self::assertCacheFilesAbsent($app);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public static function configuration(string $root): \DOMDocument
    {
        $xml = new \DOMDocument;
        $xml->load($root.'/phpunit.xml', LIBXML_NONET);
        $xml->documentElement->setAttribute('bootstrap', $root.'/tests/mariadb-bootstrap.php');
        $xml->documentElement->setAttribute('xsi:noNamespaceSchemaLocation', $root.'/vendor/phpunit/phpunit/phpunit.xsd');
        $xpath = new \DOMXPath($xml);
        foreach ($xpath->query('//directory | //testsuite/file') as $node) {
            $node->nodeValue = $root.'/'.trim($node->textContent);
        }
        $php = $xml->getElementsByTagName('php')->item(0);
        foreach (iterator_to_array($php->getElementsByTagName('env')) as $node) {
            if (str_starts_with($node->getAttribute('name'), 'DB_')) {
                $php->removeChild($node);
            } else {
                $node->setAttribute('force', 'true');
            }
        }

        return $xml;
    }

    /** @param array<string, mixed> $target */
    public static function assertTarget(array $target): void
    {
        $expected = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '33079',
            'database' => 'zavsync_v2_stage15_cert', 'username' => 'zavsync_stage15'];
        foreach ($expected as $key => $value) {
            if ((string) ($target[$key] ?? '') !== $value) {
                throw new RuntimeException('Certification refused: unsafe '.$key.'.');
            }
        }
        foreach (['url', 'unix_socket', 'read', 'write'] as $key) {
            if (! empty($target[$key])) {
                throw new RuntimeException('Certification refused: connection override '.$key.'.');
            }
        }
    }

    public static function assertServer(string $driver, string $database, string $version): void
    {
        if ($driver !== 'mysql' || $database !== 'zavsync_v2_stage15_cert'
            || ! preg_match('/^11\.8\.9-MariaDB(?:-|$)/i', $version)) {
            throw new RuntimeException('Certification refused: unexpected database engine, database, or version.');
        }
    }

    public static function assertProcess(): void
    {
        if (getenv('MARIADB_CERTIFICATION_RESET') !== 'zavsync_v2_stage15_cert') {
            throw new RuntimeException('Certification requires explicit disposable-database reset consent.');
        }
        self::assertTarget(['driver' => getenv('DB_CONNECTION'), 'host' => getenv('DB_HOST'),
            'port' => getenv('DB_PORT'), 'database' => getenv('DB_DATABASE'),
            'username' => getenv('DB_USERNAME'), 'url' => getenv('DB_URL'), 'unix_socket' => getenv('DB_SOCKET')]);
        if (getenv('DB_PASSWORD') === false || getenv('DB_PASSWORD') === '') {
            throw new RuntimeException('Certification requires a privately supplied password.');
        }
        if (getenv('TEST_TOKEN') || getenv('LARAVEL_PARALLEL_TESTING')) {
            throw new RuntimeException('Certification must run serially.');
        }
    }

    public static function guard(Application $app): void
    {
        self::assertProcess();
        if ($app->configurationIsCached() || $app->routesAreCached() || ! $app->environment('testing')
            || $app['config']->get('database.default') !== 'mysql') {
            throw new RuntimeException('Certification refused: cached configuration or incorrect test environment.');
        }
        $connection = $app['db']->connection();
        self::assertTarget($connection->getConfig());
        try {
            $server = $connection->selectOne('SELECT DATABASE() AS database_name, VERSION() AS server_version');
            self::assertServer($connection->getDriverName(), $server->database_name, $server->server_version);
        } catch (\Throwable) {
            throw new RuntimeException('Certification server assertion failed; inspect the disposable connection privately.');
        }
        Http::preventStrayRequests();
        $app->bind(SmtpEmailGateway::class, function (): never {
            throw new RuntimeException('Real SMTP is disabled during certification; bind a test gateway.');
        });
    }
}
