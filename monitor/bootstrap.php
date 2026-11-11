<?php
declare(strict_types=1);

// The web process receives only its dedicated environment and password file.
// In particular, it must never load the owner's .env.
$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
date_default_timezone_set('UTC');
umask(0077);
return new \ListenLab\Config($root);
