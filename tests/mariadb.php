<?php

use Tests\MariaDbCertification;

/** Generate a credential-free PHPUnit configuration from the normal suite. */
require_once dirname(__DIR__).'/vendor/autoload.php';

$mode = $argv[1] ?? '';
if (! in_array($mode, ['preflight', 'stage15', 'all'], true) || count($argv) !== 2) {
    fwrite(STDERR, "Usage: php tests/mariadb.php preflight|stage15|all\n");
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
foreach (['APP_ENV' => 'testing', 'DB_URL' => '', 'DB_SOCKET' => '', 'MAIL_MAILER' => 'array',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'FBR_PAKISTAN_SUBMISSION_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if ($mode === 'preflight') {
    require __DIR__.'/mariadb-bootstrap.php';
    exit(0);
}
$temporary = tempnam(sys_get_temp_dir(), 'zavsync-mariadb-phpunit-');
try {
    $xml->save($temporary);
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/vendor/bin/phpunit')
        .' --configuration '.escapeshellarg($temporary).' --do-not-cache-result';
    if ($mode === 'stage15') {
        $command .= ' '.escapeshellarg(__DIR__.'/Feature/Stage15');
    }
    passthru($command, $status);
} finally {
    unlink($temporary);
}
exit($status);
