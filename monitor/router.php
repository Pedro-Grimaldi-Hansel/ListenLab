<?php
declare(strict_types=1);

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'");
require_once dirname(__DIR__) . '/src/Monitoring/LocalRequest.php';
$publishedPort = filter_var(getenv('MONITOR_PORT') ?: ($_SERVER['SERVER_PORT'] ?? ''), FILTER_VALIDATE_INT);
if ($publishedPort === false || !\ListenLab\Monitoring\LocalRequest::allows($_SERVER, $publishedPort)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') echo '{"error":"Acesso permitido somente pela origem local do monitor."}';
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}
if ($path === '/') {
    header('Location: /analytics', true, 302);
    exit;
}
$files = ['/dashboard' => ['index.html','text/html; charset=utf-8'], '/dashboard/' => ['index.html','text/html; charset=utf-8'], '/analytics' => ['analytics.html','text/html; charset=utf-8'], '/analytics/' => ['analytics.html','text/html; charset=utf-8'], '/assets/analytics.js' => ['assets/analytics.js','application/javascript; charset=utf-8'], '/assets/analytics.css' => ['assets/analytics.css','text/css; charset=utf-8'], '/assets/style.css' => ['assets/style.css','text/css; charset=utf-8'], '/assets/app.js' => ['assets/app.js','application/javascript; charset=utf-8'], '/assets/theme.js' => ['assets/theme.js','application/javascript; charset=utf-8'], '/assets/tests.js' => ['assets/tests.js','application/javascript; charset=utf-8']];
$files['/assets/icons.svg'] = ['assets/icons.svg', 'image/svg+xml'];
if (isset($files[$path])) {
    header('Content-Type: ' . $files[$path][1]);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
        readfile(__DIR__ . '/public/' . $files[$path][0]);
    }
    exit;
}
if ($path === '/tests' || $path === '/tests/') {
    header('Content-Type: text/html; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
        require __DIR__ . '/tests-page.php';
    }
    exit;
}
header('Content-Type: application/json; charset=utf-8');
$db = null;
try {
    $config = require __DIR__ . '/bootstrap.php';
    $db = \ListenLab\Database::monitor($config);
    $db->beginTransaction();
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $monitor = new \ListenLab\Monitoring\Monitor($db, $config);
    if ($path === '/api/analytics') {
        try {
            $period = \ListenLab\Analytics\Period::fromQuery($_GET, $config->get('MONITOR_TIMEZONE', 'America/Sao_Paulo'));
            $data = (new \ListenLab\Analytics\Analytics($db))->overview($period);
        } catch (\InvalidArgumentException $error) {
            http_response_code(400);
            $data = ['error' => $error->getMessage()];
        }
    } elseif ($path === '/api/status') {
        $data = $monitor->overview();
    } elseif ($path === '/api/batches') {
        $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
        $status = $_GET['status'] ?? 'all';
        if ($page === false || $page < 1 || $page > 100000 || !is_string($status) || !in_array($status, ['all','completed','partial','failed','running'], true)) {
            http_response_code(400);
            $data = ['error' => 'Filtro de execuções inválido.'];
        } else {
            $data = $monitor->batches($page, $status);
        }
    } elseif (preg_match('#^/api/batches/([1-9][0-9]{0,17})$#D', $path, $match)) {
        $data = $monitor->detail((int) $match[1]);
        if ($data === null) {
            http_response_code(404);
            $data = ['error' => 'Execução não encontrada.'];
        }
    } else {
        http_response_code(404);
        $data = ['error' => 'Página não encontrada.'];
    }
    $db->commit();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} catch (\Throwable $error) {
    if ($db?->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(503);
    $message = $error instanceof \PDOException && $error->getCode() === '42P01'
        ? 'Atualize o banco com php app db:migrate para abrir o monitor.'
        : 'Não foi possível consultar o banco. Confira se o serviço db está em execução.';
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
}
