<?php

use Tests\MariaDbCertification;

require_once dirname(__DIR__).'/vendor/autoload.php';

MariaDbCertification::assertProcess();
define('ZAVSYNC_MARIADB_CERTIFICATION', true);
$application = MariaDbCertification::bootApplication();
MariaDbCertification::guard($application);
fwrite(STDOUT, "MariaDB safety assertion PASS: mysql / zavsync_v2_stage15_cert / MariaDB 11.8.9\n");
$application['db']->disconnect();
$application->flush();
