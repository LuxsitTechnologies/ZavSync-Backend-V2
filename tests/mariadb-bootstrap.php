<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\MariaDbCertification;

require_once dirname(__DIR__).'/vendor/autoload.php';

MariaDbCertification::assertProcess();
define('ZAVSYNC_MARIADB_CERTIFICATION', true);
$application = require dirname(__DIR__).'/bootstrap/app.php';
if ($application->configurationIsCached() || $application->routesAreCached()) {
    throw new RuntimeException('Certification refused: clear generated configuration/route caches in a separate step.');
}
$application->make(Kernel::class)->bootstrap();
MariaDbCertification::guard($application);
fwrite(STDOUT, "MariaDB safety assertion PASS: mysql / zavsync_v2_stage15_cert / MariaDB 11.8.9\n");
$application['db']->disconnect();
$application->flush();
