<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    throw new RuntimeException('Execute composer install antes de iniciar o ListenLab.');
}
require_once __DIR__ . '/vendor/autoload.php';
date_default_timezone_set('UTC');
umask(0077);
return \ListenLab\Config::load(__DIR__);
