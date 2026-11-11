<?php
declare(strict_types=1);

namespace ListenLab\Tests\Integration;

use GuzzleHttp\Client;
use ListenLab\Config;
use ListenLab\Database;
use ListenLab\Ingestion\Batches;
use ListenLab\Migrator;
use ListenLab\Monitoring\MonitorAccess;
use ListenLab\Scheduling\Runtime;
use ListenLab\Tests\Support\TemporaryDirectory;
use PDO;
use PHPUnit\Framework\TestCase;

final class MonitorHttpTest extends TestCase
{
    use TemporaryDirectory;
    private PDO $db;
    private Config $monitorConfig;
    private string $role;
    private string $schema;
    private Client $http;
    private mixed $server = null;
    private array $pipes = [];

    protected function setUp(): void
    {
        $this->createTemporary();
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
        $this->role = 'monitor_test_' . bin2hex(random_bytes(8));
        $this->monitorConfig = new Config($config->root, [
            'POSTGRES_DB' => $database, 'MONITOR_DB_USER' => $this->role,
            'MONITOR_DB_PASSWORD_FILE' => $this->temporary . '/monitor-password',
        ]);
        (new MonitorAccess($this->db, $this->monitorConfig))->provision();

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
        if ($socket === false) {
            throw new \RuntimeException('Cannot reserve a local HTTP test port: ' . $error);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $environment = array_merge(array_intersect_key(getenv(), array_flip(['PATH', 'TMPDIR', 'SSL_CERT_FILE'])), [
            'DB_HOST' => $config->get('DB_HOST', '127.0.0.1'),
            'DB_PORT' => $config->get('DB_PORT', '5432'),
            'POSTGRES_DB' => $database,
            'MONITOR_DB_USER' => $this->role,
            'MONITOR_DB_PASSWORD_FILE' => $this->temporary . '/monitor-password',
            'MONITOR_PORT' => (string) parse_url('http://' . $address, PHP_URL_PORT),
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
        if (isset($this->db, $this->role, $this->monitorConfig)) {
            $database = '"' . str_replace('"', '""', $this->monitorConfig->get('POSTGRES_DB')) . '"';
            $this->db->exec('REVOKE CONNECT ON DATABASE ' . $database . ' FROM "' . $this->role . '"');
            $this->db->exec('DROP ROLE "' . $this->role . '"');
        }
        $this->removeTemporary();
    }

    public function testPageAssetsAndHeadResponses(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $response = $this->http->request($method, '/', ['allow_redirects' => false]);
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/analytics', $response->getHeaderLine('Location'));
            self::assertSame('', (string) $response->getBody());
        }
        $pages = [];
        foreach (['/dashboard' => 'text/html', '/dashboard/' => 'text/html', '/analytics' => 'text/html', '/analytics/' => 'text/html', '/tests' => 'text/html', '/tests/' => 'text/html', '/assets/app.js' => 'application/javascript', '/assets/style.css' => 'text/css', '/assets/theme.js' => 'application/javascript', '/assets/tests.js' => 'application/javascript', '/assets/analytics.js' => 'application/javascript', '/assets/analytics.css' => 'text/css', '/assets/icons.svg' => 'image/svg+xml'] as $path => $type) {
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
        self::assertStringContainsString('data-page="analytics"', $pages['/analytics']);
        self::assertStringContainsString('id="ranking-tracks"', $pages['/analytics']);
        self::assertStringContainsString('/assets/analytics.js', $pages['/analytics']);
        self::assertStringNotContainsString('/assets/app.js', $pages['/analytics']);
        foreach (['/dashboard', '/analytics', '/tests'] as $path) {
            $document = new \DOMDocument();
            @$document->loadHTML('<?xml encoding="UTF-8">' . $pages[$path]);
            $xpath = new \DOMXPath($document);
            self::assertSame(1, $xpath->query('//nav[@aria-label="Menu principal"]')->length, $path);
            $links = $xpath->query('//nav[@aria-label="Menu principal"]/a');
            self::assertSame(['/analytics', '/dashboard', '/tests'], array_map(
                static fn (\DOMElement $link): string => $link->getAttribute('href'), iterator_to_array($links),
            ));
            self::assertSame($path, $xpath->query('//nav/a[@aria-current="page"]')->item(0)?->getAttribute('href'));
            self::assertSame('/analytics', $xpath->query('//a[contains(@class,"brand")]')->item(0)?->getAttribute('href'));
            self::assertSame(1, $xpath->query('//a[@href="#main-content"]')->length);
            self::assertSame(1, $xpath->query('//main[@id="main-content"]')->length);
            self::assertStringNotContainsString('screen-switcher', $pages[$path]);
            $ids = array_map(static fn (\DOMElement $node): string => $node->getAttribute('id'), iterator_to_array($xpath->query('//*[@id]')));
            self::assertSame($ids, array_values(array_unique($ids)), 'Unique IDs on ' . $path);
            if ($path === '/dashboard') {
                self::assertSame(1, $xpath->query('//button[@id="help-button"]')->length);
                self::assertLessThan(strpos($pages[$path], 'class="storage-card"'), strpos($pages[$path], 'class="history-card"'));
            } elseif ($path === '/analytics') {
                self::assertSame(3, $xpath->query('//*[@role="tab"]')->length);
                self::assertSame(1, $xpath->query('//*[@role="tab" and @aria-selected="true"]')->length);
                self::assertSame(3, $xpath->query('//*[@role="tabpanel" and @tabindex="0"]')->length);
                self::assertSame(2, $xpath->query('//*[@role="tabpanel" and @hidden]')->length);
                foreach ($xpath->query('//*[@role="tab"]') as $tab) {
                    self::assertSame($tab->getAttribute('id'), $xpath->query('//*[@id="' . $tab->getAttribute('aria-controls') . '"]')->item(0)?->getAttribute('aria-labelledby'));
                }
            }
        }
        foreach (['/dashboard', '/analytics', '/tests', '/api/status', '/api/batches', '/api/analytics'] as $path) {
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

    public function testAnalyticsApiUsesTheRestrictedReaderAndDoesNotExposePrivateFields(): void
    {
        $track = $this->db->query("INSERT INTO tracks(name,observed_at,isrc) VALUES ('Faixa fictícia <script>',now(),'ZZPRIVATE') RETURNING id")->fetchColumn();
        foreach (['2026-11-11T03:00:00Z', '2026-11-12T03:00:00Z'] as $date) {
            Database::query($this->db, "INSERT INTO plays(track_id,played_at,timestamp_semantics,context_uri,platform) VALUES (?,?,'synthetic','synthetic-private-context','private-platform')", [$track, $date]);
        }
        $response = $this->http->get('/api/analytics?period=custom&from=2026-11-11&to=2026-11-11&limit=20');
        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('America/Sao_Paulo', $data['timezone']);
        self::assertSame(2, $data['dataset']['plays']);
        self::assertSame(1, $data['summary']['plays']);
        self::assertSame(20, $data['period']['limit']);
        self::assertSame('Faixa fictícia <script>', $data['rankings']['tracks'][0]['name']);
        self::assertSame(1, $data['distribution']['hours'][0]['plays']);
        foreach (['synthetic-private-context', 'private-platform', 'ZZPRIVATE', 'context_uri', 'duration_ms', 'payload'] as $private) self::assertStringNotContainsString($private, $body);
        self::assertSame(2, (int) $this->db->query('SELECT count(*) FROM plays')->fetchColumn());
    }

    public function testInvalidAnalyticsFiltersReturnBadRequest(): void
    {
        foreach (['period[]=all', 'period=unknown', 'limit=0', 'limit=100000', 'limit[]=10', 'period=custom',
            'period=custom&from=2026-02-30&to=2026-03-01', 'period=custom&from=2026-11-12&to=2026-11-11',
            'period=custom&from[]=2026-11-11&to=2026-11-12', 'period=7d&from=2026-11-11'] as $query) {
            $response = $this->http->get('/api/analytics?' . $query);
            self::assertSame(400, $response->getStatusCode(), $query);
            self::assertArrayHasKey('error', json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testWriteMethodsAreRejected(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            foreach (['/api/status', '/api/analytics', '/dashboard', '/analytics', '/tests'] as $path) {
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

    public function testForeignHostsOriginsAndCrossSiteRequestsAreRejected(): void
    {
        $origin = rtrim((string)$this->http->getConfig('base_uri'), '/');
        foreach ([
            ['Host' => 'audit.invalid:8787'],
            ['Host' => '127.0.0.1.audit.invalid:8787'],
            ['Host' => 'localhost.:8787'],
            ['Host' => '127.0.0.1:1'],
            ['Origin' => 'http://audit.invalid'],
            ['Origin' => 'null'],
            ['Origin' => 'https://audit.invalid'],
            ['Origin' => $origin . '.invalid'],
            ['Sec-Fetch-Site' => 'cross-site'],
        ] as $headers) {
            foreach (['/api/status', '/api/analytics', '/dashboard', '/analytics', '/tests'] as $path) {
                self::assertSame(403,$this->http->get($path,['headers'=>$headers])->getStatusCode());
            }
        }
        self::assertSame(200,$this->http->get('/api/status',['headers'=>['Origin'=>$origin,'Sec-Fetch-Site'=>'same-origin']])->getStatusCode());
        self::assertSame('',(string)$this->http->head('/api/status',['headers'=>['Host'=>'audit.invalid']])->getBody());
    }

    public function testTopLevelNavigationToUiDoesNotGrantCrossSiteApiAccess(): void
    {
        $headers=['Sec-Fetch-Site'=>'cross-site','Sec-Fetch-Mode'=>'navigate','Sec-Fetch-Dest'=>'document'];
        foreach(['/dashboard','/analytics','/tests']as$path)self::assertSame(200,$this->http->get($path,['headers'=>$headers])->getStatusCode());
        self::assertSame(302,$this->http->get('/',['headers'=>$headers,'allow_redirects'=>false])->getStatusCode());
        self::assertSame(403,$this->http->get('/api/status',['headers'=>$headers])->getStatusCode());
        self::assertSame(403,$this->http->get('/api/analytics',['headers'=>$headers])->getStatusCode());
        self::assertSame(403,$this->http->get('/dashboard',['headers'=>array_replace($headers,['Sec-Fetch-Dest'=>'iframe'])])->getStatusCode());
    }

    public function testMonitorRoleCannotWriteOrReadPlaybackPayloadsEvenAfterDisablingReadOnlyDefault(): void
    {
        $reader=Database::monitor($this->monitorConfig);
        self::assertSame('on',$reader->query('SHOW default_transaction_read_only')->fetchColumn());
        $flags=$reader->query('SELECT rolsuper,rolcreatedb,rolcreaterole,rolreplication,rolbypassrls FROM pg_roles WHERE rolname=current_user')->fetch(PDO::FETCH_ASSOC);
        foreach($flags as $value)self::assertFalse($value);
        self::assertSame(0,(int)$reader->query('SELECT count(*) FROM plays')->fetchColumn());
        $reader->exec('SET default_transaction_read_only=off');
        foreach([
            "INSERT INTO ingestion_batches(source_id,operation)VALUES(1,'synthetic')",
            'UPDATE scheduler_runtime SET interval_seconds=16',
            'TRUNCATE raw_artifacts CASCADE',
            'SELECT context_uri FROM plays',
            'SELECT ms_played FROM plays',
            'SELECT * FROM tracks',
            'SELECT account_sha256 FROM spotify_account_binding',
            'SELECT payload FROM ingestion_records',
            'SELECT metadata FROM raw_artifacts',
        ] as $sql){
            try{$reader->exec($sql);self::fail('Monitor permissions must reject the statement.');}
            catch(\PDOException $error){self::assertSame('42501',$error->getCode());}
        }
    }

    public function testMonitorProvisionPreservesItsSecretAndRefusesAnUnmanagedRole(): void
    {
        $file=$this->temporary.'/monitor-password';$before=hash_file('sha256',$file);
        self::assertTrue((new MonitorAccess($this->db,$this->monitorConfig))->provision()['read_only']);
        self::assertSame($before,hash_file('sha256',$file));
        $unmanaged='unmanaged_test_'.bin2hex(random_bytes(8));$this->db->exec('CREATE ROLE "'.$unmanaged.'" NOLOGIN');
        try{
            $config=new Config(dirname(__DIR__,2),['MONITOR_DB_USER'=>$unmanaged,'MONITOR_DB_PASSWORD_FILE'=>$file]);
            try{(new MonitorAccess($this->db,$config))->provision();self::fail('Unmanaged roles must be preserved.');}
            catch(\ListenLab\AppError $error){self::assertStringContainsString('não é gerenciado',$error->getMessage());}
            self::assertFalse(Database::query($this->db,'SELECT rolcanlogin FROM pg_roles WHERE rolname=?',[$unmanaged])->fetchColumn());
        }finally{$this->db->exec('DROP ROLE "'.$unmanaged.'"');}
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
