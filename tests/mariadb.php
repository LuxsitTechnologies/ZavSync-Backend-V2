<?php

use Symfony\Component\Process\Process;
use Tests\MariaDbCertification;

/** Generate a credential-free PHPUnit configuration from the normal suite. */
require_once dirname(__DIR__).'/vendor/autoload.php';

$mode = $argv[1] ?? '';
if (! in_array($mode, ['preflight', 'stage15', 'all', 'concurrency'], true) || count($argv) !== 2) {
    fwrite(STDERR, "Usage: php tests/mariadb.php preflight|stage15|all|concurrency\n");
    exit(2);
}
try {
    MariaDbCertification::assertProcess();
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}
$root = dirname(__DIR__);
$xml = MariaDbCertification::configuration($root);
putenv('MARIADB_CERTIFICATION_CONCURRENCY='.($mode === 'concurrency' ? '1' : '0'));
foreach (['APP_ENV' => 'testing', 'DB_URL' => '', 'DB_SOCKET' => '', 'MAIL_MAILER' => 'array',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'FBR_PAKISTAN_SUBMISSION_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if ($mode === 'preflight') {
    $application = MariaDbCertification::bootApplication();
    MariaDbCertification::guard($application);
    fwrite(STDOUT, "MariaDB safety assertion PASS: mysql / zavsync_v2_stage15_cert / MariaDB 11.8.9\n");
    $application->terminate();
    $application['db']->disconnect();
    exit(0);
}
$preflight = new Process([PHP_BINARY, __FILE__, 'preflight'], $root);
$preflight->setTimeout(30);
$status = $preflight->run(function (string $type, string $output): void {
    fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
});
if ($status !== 0) {
    exit($status);
}
$temporary = tempnam(sys_get_temp_dir(), 'zavsync-mariadb-phpunit-');
try {
    $xml->save($temporary);
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/vendor/bin/phpunit')
        .' --configuration '.escapeshellarg($temporary).' --do-not-cache-result --fail-on-risky';
    if ($mode === 'stage15') {
        $command .= ' '.escapeshellarg(__DIR__.'/Feature/Stage15');
    }
    if ($mode === 'concurrency') {
        $command .= ' --fail-on-skipped --fail-on-warning --fail-on-phpunit-deprecation --filter '
            .escapeshellarg('MariaDbConcurrencyTest|FbrSubmissionLeaseConcurrencyTest|LegacyImportOwnershipConcurrencyTest|TechnicalIdentifierTest|ClaimGenerationTest|LegacyImportGenerationTest|CertificationDateTimeTest|InvoiceCreationRollbackTest|PakistanFbrDomainTest|MigrationIntegrityTest')
            .' '.escapeshellarg(__DIR__.'/Feature/Stage15');
    }
    passthru($command, $status);
} finally {
    unlink($temporary);
}
exit($status);
