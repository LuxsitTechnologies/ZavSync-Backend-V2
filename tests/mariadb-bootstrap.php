<?php

use Tests\MariaDbCertification;

require_once dirname(__DIR__).'/vendor/autoload.php';

MariaDbCertification::assertProcess();
define('ZAVSYNC_MARIADB_CERTIFICATION', true);
