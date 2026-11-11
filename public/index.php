<?php
declare(strict_types=1);

// This router deliberately serves no files from storage/, tokens/ or the root.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
try {
    $config = require dirname(__DIR__) . '/bootstrap.php';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $expectedPath = parse_url($config->required('SPOTIFY_REDIRECT_URI'), PHP_URL_PATH);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || $path !== $expectedPath) {
        http_response_code(404);
        echo 'Callback Spotify. Inicie a autorização com php app spotify:auth.';
        return;
    }
    $transport = new \ListenLab\Http\GuzzleTransport();
    $accounts = new \ListenLab\Spotify\AccountGuard(\ListenLab\Database::connect($config), $transport);
    (new \ListenLab\Spotify\OAuth($config, $transport, $accounts->verify(...)))->complete($_GET);
    echo 'Autorização salva. Pode fechar esta aba e executar php app spotify:sync.';
} catch (\Throwable $error) {
    http_response_code(400);
    echo $error instanceof \ListenLab\AppError ? $error->getMessage() : 'Falha na autorização; consulte a configuração local. Detalhes sensíveis foram omitidos.';
}
