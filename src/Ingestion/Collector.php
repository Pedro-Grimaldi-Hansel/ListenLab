<?php
declare(strict_types=1);

namespace ListenLab\Ingestion;

use ListenLab\AppError;
use ListenLab\Database;
use ListenLab\Http\Response;
use ListenLab\Spotify\Api;
use ListenLab\Storage\RawStore;
use PDO;

final class Collector
{
    private readonly Batches $batches;
    private readonly Ingestor $ingestor;

    public function __construct(private readonly PDO $db, private readonly RawStore $raw, private readonly ?Api $api = null)
    {
        $this->batches = new Batches($db);
        $this->ingestor = new Ingestor($db, $raw, $this->batches);
    }

    public function collect(string $operation, int $maxPages, int $limit): array
    {
        if (!in_array($operation, ['sync','probe'], true) || $maxPages < 1 || $limit < 1 || $limit > 50 || $this->api === null) {
            throw new AppError('Configuração de coleta inválida.');
        }
        return $this->run($operation, function (int $batchId, Summary $summary) use ($operation, $maxPages, $limit): string {
            $query = ['limit' => $limit];
            $visited = [];
            $pageSignatures = [];
            for ($number = 0; $number < $maxPages; ++$number) {
                $queryKey = $this->queryKey($query);
                if (isset($visited[$queryKey])) {
                    $this->batches->issue($batchId, 'repeated_cursor', 'A paginação retornou a mesma consulta; coleta interrompida.');
                    return 'repeated_cursor';
                }
                $visited[$queryKey] = true;
                $artifact = null;
                $response = $this->api->page($query, function (Response $response, array $parameters) use ($batchId, $summary, &$artifact): void {
                    $artifact = $this->ingestor->capture($batchId, $response, $parameters, $summary);
                });
                $result = $this->ingestor->page($batchId, $artifact, $response->body, $summary);
                $this->batches->progress($batchId, $summary, $operation, 'running', 'collecting');
                if ($result['count'] === 0) {
                    return 'empty_page';
                }
                $keys = $result['keys'];
                sort($keys);
                $signature = hash('sha256', CanonicalJson::encode($keys));
                if (isset($pageSignatures[$signature])) {
                    $this->batches->issue($batchId, 'no_progress', 'Página repetida apesar da paginação; limite observado, sem presumir cobertura total.', $artifact['id']);
                    return 'no_progress';
                }
                $pageSignatures[$signature] = true;
                $page = $result['page'];
                if ($operation === 'probe') {
                    if (isset($page->cursors) && !$page->cursors instanceof \stdClass) {
                        throw new AppError('Estrutura de cursors incompatível; investigação interrompida com RAW preservado.');
                    }
                    // before is explicitly documented as the previous-page cursor.
                    // Do not fabricate a cursor from played_at or interpret next=null
                    // as proof that backward history is unavailable.
                    $cursor = $page->cursors->before ?? null;
                    if ($cursor === null) {
                        return 'no_before_cursor';
                    }
                    if (!is_string($cursor) || !ctype_digit($cursor)) {
                        throw new AppError('Cursor before inválido; investigação interrompida.');
                    }
                    if (isset($query['before']) && (int) $cursor >= (int) $query['before']) {
                        $this->batches->issue($batchId, 'non_decreasing_cursor', 'Cursor before não retrocedeu; não há evidência de cobertura completa.', $artifact['id']);
                        return 'non_decreasing_cursor';
                    }
                    $query = ['limit' => $limit, 'before' => $cursor];
                } else {
                    $next = $page->next ?? null;
                    if ($next === null) {
                        return 'next_exhausted';
                    }
                    if (!is_string($next) || $next === '') {
                        throw new AppError('Campo next incompatível com a documentação.');
                    }
                    $query = Api::nextQuery($next);
                }
            }
            $this->batches->issue($batchId, 'page_limit', 'Orçamento de páginas atingido; a coleta pode ser continuada com um orçamento maior.');
            return 'page_limit';
        });
    }

    public function replay(int $artifactId): array
    {
        return $this->run('replay', function (int $batchId, Summary $summary) use ($artifactId): string {
            $original = Database::query($this->db, 'SELECT * FROM raw_artifacts WHERE id = ?', [$artifactId])->fetch(PDO::FETCH_ASSOC);
            if (!$original || (int) $original['http_status'] !== 200) {
                throw new AppError('Escolha um RAW registrado de resposta HTTP 200 do recently-played.');
            }
            $bytes = $this->raw->readVerified($original['path'], $original['sha256']);
            $metadata = json_decode($original['metadata'], true, 512, JSON_THROW_ON_ERROR);
            $observedAt = $metadata['original_received_at'] ?? $original['received_at'];
            $artifact = $this->ingestor->capture($batchId, new Response(200, $bytes), [], $summary, [
                'replay_of_artifact_id' => $artifactId, 'original_received_at' => $observedAt,
            ]);
            $this->ingestor->page($batchId, $artifact, $bytes, $summary);
            return 'replayed_artifact';
        });
    }

    private function run(string $operation, callable $work): array
    {
        $locked = $this->db->query('SELECT pg_try_advisory_lock(194701, 2)')->fetchColumn();
        if (!in_array($locked, [true, 't', 1, '1'], true)) {
            throw new AppError('Outra ingestão está em execução. Tente novamente após sua conclusão.');
        }
        try {
            $batchId = $this->batches->start($operation);
            $summary = new Summary();
            try {
                $reason = $work($batchId, $summary);
                $incomplete = in_array($reason, ['page_limit','no_progress','repeated_cursor','non_decreasing_cursor'], true);
                $status = $incomplete || $summary->failed > 0 || $summary->partial > 0 ? 'partial' : 'completed';
                return $this->batches->progress($batchId, $summary, $operation, $status, $reason);
            } catch (\Throwable $error) {
                $safeMessage = $error instanceof AppError ? $error->getMessage() : 'Falha interna na ingestão; consulte os artefatos e registros pendentes deste batch.';
                $this->batches->issue($batchId, 'collection_failed', $safeMessage);
                return $this->batches->progress($batchId, $summary, $operation, 'failed', 'error', $safeMessage);
            }
        } finally {
            $this->db->exec('SELECT pg_advisory_unlock(194701, 2)');
        }
    }

    private function queryKey(array $query): string
    {
        ksort($query);
        return CanonicalJson::encode(array_map(strval(...), $query));
    }
}
