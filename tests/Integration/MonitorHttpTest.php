<?php
declare(strict_types=1);

namespace ListenLab\Tests\Integration;

use GuzzleHttp\Client;
use ListenLab\Config;
use ListenLab\Database;
use ListenLab\Ingestion\Batches;
use ListenLab\Migrator;
use ListenLab\Scheduling\Runtime;
use PDO;
use PHPUnit\Framework\TestCase;

final class MonitorHttpTest extends TestCase
{
    private PDO $db;
    private string $schema;
    private Client $http;
    private mixed $server = null;
    private array $pipes = [];

    protected function setUp(): void
    {
        $config = Config::load(dirname(__DIR__, 2));
        $database = $config->get('TEST_DB_NAME', 'listenlab_test');
        if (!preg_match('/^[a-zA-Z0-9_]+_test$/D', $database) || $database === $config->get('POSTGRES_DB', 'listenlab')) {
            throw new \RuntimeException('Monitor HTTP tests require a separate database ending in _test.');
        }
        $this->db = Database::connect($config, $database);
        $this->schema = 'monitor_http_' . bin2hex(random_bytes(8));
        $this->db->exec('CREATE SCHEMA ' . $this->schema);
        $this->db->exec('SET search_path TO ' . $this->schema);
        (new Migrator($this->db, $config->root . '/migrations'))->migrate();

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
        if ($socket === false) {
            throw new \RuntimeException('Cannot reserve a local HTTP test port: ' . $error);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $environment = array_merge(getenv(), [
            'POSTGRES_DB' => $database,
            // The HTTP process only sees this test schema, and cannot write.
            'PGOPTIONS' => '-c search_path=' . $this->schema . ' -c default_transaction_read_only=on',
        ]);
        $this->server = proc_open(
            [PHP_BINARY, '-S', $address, '-t', 'monitor/public', 'monitor/router.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
            $config->root,
            $environment,
        );
        if (!is_resource($this->server)) {
            throw new \RuntimeException('Cannot start the isolated monitor HTTP server.');
        }
        fclose($this->pipes[0]);
        unset($this->pipes[0]);
        $this->http = new Client(['base_uri' => 'http://' . $address, 'http_errors' => false, 'timeout' => 3, 'proxy' => '']);
        $deadline = microtime(true) + 5;
        do {
            $connection = @stream_socket_client('tcp://' . $address, $errorCode, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline && proc_get_status($this->server)['running']);
        throw new \RuntimeException('The monitor HTTP server did not become ready.');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            $deadline = microtime(true) + 2;
            while (proc_get_status($this->server)['running'] && microtime(true) < $deadline) {
                usleep(20000);
            }
            if (proc_get_status($this->server)['running']) {
                proc_terminate($this->server, 9);
            }
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($this->server);
        }
        if (isset($this->db, $this->schema)) {
            $this->db->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function testPageAssetsAndHeadResponses(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $response = $this->http->request($method, '/', ['allow_redirects' => false]);
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/dashboard', $response->getHeaderLine('Location'));
            self::assertSame('', (string) $response->getBody());
        }
        $pages = [];
        foreach (['/dashboard' => 'text/html', '/dashboard/' => 'text/html', '/tests' => 'text/html', '/tests/' => 'text/html', '/assets/app.js' => 'application/javascript', '/assets/style.css' => 'text/css', '/assets/theme.js' => 'application/javascript', '/assets/tests.js' => 'application/javascript'] as $path => $type) {
            $response = $this->http->get($path);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringStartsWith($type, $response->getHeaderLine('Content-Type'));
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
            $body = (string) $response->getBody();
            self::assertNotSame('', $body);
            $pages[$path] = $body;
        }
        self::assertStringContainsString('data-page="dashboard"', $pages['/dashboard']);
        self::assertStringContainsString('id="history-body"', $pages['/dashboard']);
        self::assertStringNotContainsString('id="test-groups"', $pages['/dashboard']);
        self::assertStringNotContainsString('/assets/tests.js', $pages['/dashboard']);
        self::assertStringContainsString('data-page="tests"', $pages['/tests']);
        self::assertStringContainsString('id="test-groups"', $pages['/tests']);
        self::assertStringNotContainsString('id="history-body"', $pages['/tests']);
        self::assertStringNotContainsString('/assets/app.js', $pages['/tests']);
        foreach (['/dashboard', '/tests', '/api/status', '/api/batches'] as $path) {
            $response = $this->http->head($path);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('', (string) $response->getBody());
        }
    }

    public function testReadOnlyApiShowsRuntimeHistoryAndSelectedDetails(): void
    {
        $instance = str_repeat('c', 32);
        $runtime = new Runtime($this->db);
        $runtime->start($instance, 900);
        $runtime->waiting($instance, time() + 900, 0);
        $id = (new Batches($this->db))->start('sync');
        Database::query($this->db, "UPDATE ingestion_batches SET status='completed', finished_at=now(), metadata=CAST(? AS jsonb) WHERE id=?", [
            json_encode(['pages' => 2, 'access_token' => 'synthetic-private-token'], JSON_THROW_ON_ERROR), $id,
        ]);
        $statusResponse = $this->http->get('/api/status');
        self::assertSame(200, $statusResponse->getStatusCode());
        $status = json_decode((string) $statusResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('waiting', $status['scheduler']['status']);
        self::assertSame(900, $status['scheduler']['interval_seconds']);
        self::assertSame($id, $status['latest_sync']['id']);
        self::assertSame(0, $status['preserved']['raw_files']);

        $history = json_decode((string) $this->http->get('/api/batches?status=completed&page=1')->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $history['total']);
        self::assertSame($id, $history['items'][0]['id']);
        $detailResponse = $this->http->get('/api/batches/' . $id);
        self::assertSame(200, $detailResponse->getStatusCode());
        $detailBody = (string) $detailResponse->getBody();
        self::assertStringNotContainsString('synthetic-private-token', $detailBody);
        self::assertStringNotContainsString('access_token', $detailBody);
        self::assertSame(2, json_decode($detailBody, true, 512, JSON_THROW_ON_ERROR)['pages']);
        self::assertSame(1, (int) $this->db->query('SELECT count(*) FROM ingestion_batches')->fetchColumn());
    }

    public function testInvalidFiltersReturnBadRequest(): void
    {
        foreach (['page=0', 'page=-1', 'page=100001', 'page[]=1', 'status[]=all', 'status=invalid'] as $query) {
            $response = $this->http->get('/api/batches?' . $query);
            self::assertSame(400, $response->getStatusCode(), $query);
            self::assertArrayHasKey('error', json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testWriteMethodsAreRejected(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            foreach (['/api/status', '/dashboard', '/tests'] as $path) {
                $response = $this->http->request($method, $path);
                self::assertSame(405, $response->getStatusCode(), $method . ' ' . $path);
                self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
            }
        }
    }

    public function testPrivatePathsAndMissingBatchesAreNotServed(): void
    {
        foreach (['/.env', '/tokens/spotify.json', '/storage/raw/', '/storage/archive/', '/storage/backups/', '/%2e%2e/.env', '/assets/../router.php', '/test-catalog.php', '/tests-page.php', '/api/batches/999999', '/missing'] as $path) {
            $response = $this->http->get($path);
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        }
    }

    public function testCatalogueDescribesEveryTestAndCountsItsDataSets(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = require $root . '/monitor/test-catalog.php';
        $expectedFiles = array_map(static fn (string $path): string => str_replace('\\', '/', substr($path, strlen($root) + 1)), glob($root . '/tests/{Unit,Integration}/*Test.php', GLOB_BRACE));
        $catalogFiles = array_column($catalog, 'file');
        sort($expectedFiles);
        sort($catalogFiles);
        self::assertSame($expectedFiles, $catalogFiles, 'Every test file needs an explanation in the catalogue.');
        $count = 0;
        foreach ($catalog as $group) {
            $class = 'ListenLab\\Tests\\' . str_replace('/', '\\', substr($group['file'], strlen('tests/'), -4));
            $reflection = new \ReflectionClass($class);
            $methods = array_filter($reflection->getMethods(\ReflectionMethod::IS_PUBLIC), static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class && str_starts_with($method->getName(), 'test'));
            $expected = array_map(static fn (\ReflectionMethod $method): string => $method->getName(), $methods);
            $documented = array_column($group['tests'], 0);
            sort($expected);
            sort($documented);
            self::assertSame($expected, $documented, $group['file']);
            foreach ($group['tests'] as [$method, $title, $description]) {
                self::assertNotSame('', trim($title));
                self::assertNotSame('', trim($description));
            }
            foreach ($group['tests'] as $test) {
                $cases = 1;
                foreach ($reflection->getMethod($test[0])->getAttributes(\PHPUnit\Framework\Attributes\DataProvider::class) as $provider) {
                    $providerName = $provider->getArguments()[0];
                    $cases = count($class::$providerName());
                }
                self::assertSame($cases, $test[3] ?? 1, $test[0] . ' data sets');
                $count += $cases;
            }
        }
        $response = $this->http->get('/tests');
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $total = (new \DOMXPath($document))->query('//*[@id="test-total"]')->item(0);
        self::assertNotNull($total);
        self::assertSame((string) $count, trim($total->textContent));
        self::assertStringContainsString('id="theme-toggle"', $html);
        foreach ($catalog as $group) {
            foreach ($group['tests'] as $test) {
                self::assertStringContainsString('data-test-id="' . $test[0] . '"', $html);
            }
        }
    }
}
