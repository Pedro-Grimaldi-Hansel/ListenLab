<?php
declare(strict_types=1);

try {
    $config = require dirname(__DIR__) . '/bootstrap.php';
    (new \ListenLab\Scheduling\Scheduler(\ListenLab\Database::connect($config), $config))->run();
} catch (\Throwable) {
    fwrite(STDERR, "Scheduler interrompido; confira a configuração local.\n");
    exit(1);
}
