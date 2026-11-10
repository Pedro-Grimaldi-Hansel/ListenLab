#!/usr/bin/env php
<?php
declare(strict_types=1);

try {
    $config = require __DIR__ . '/bootstrap.php';
    exit(\ListenLab\Console::build($config)->run());
} catch (\Throwable $error) {
    $message = $error instanceof \ListenLab\AppError ? $error->getMessage() : 'Falha interna ou de configuração. Verifique dependências, conexão PostgreSQL e migrations; detalhes sensíveis foram omitidos.';
    fwrite(STDERR, 'Erro: ' . $message . "\n");
    exit(1);
}
