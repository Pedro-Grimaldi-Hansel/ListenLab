<?php
declare(strict_types=1);

namespace ListenLab\Ingestion;

use ListenLab\AppError;
use ListenLab\Database;
use ListenLab\Http\Response;
use ListenLab\Storage\RawStore;
use ListenLab\Storage\RawIndex;
use ListenLab\Spotify\Api;
use PDO;

final class Ingestor
{
    private readonly RecentlyPlayedParser $parser;
    private readonly Catalog $catalog;

    public function __construct(private readonly PDO $db, private readonly RawStore $raw, private readonly Batches $batches)
    {
        $this->parser = new RecentlyPlayedParser();
        $this->catalog = new Catalog($db);
    }

    public function capture(int $batchId, Response $response, array $query, Summary $summary, array $extra = []): array
    {
        $artifact = $this->raw->save($response->body, $batchId);
        $summary->rawPaths[] = $artifact->path;
        ++$summary->responses;
        try {
            $this->db->beginTransaction();
            $id = (int) Database::query($this->db, 'INSERT INTO raw_artifacts(batch_id,path,sha256,size_bytes,received_at,http_status,metadata) VALUES (?,?,?,?,?,?,CAST(? AS jsonb)) RETURNING id', [
                $batchId, $artifact->path, $artifact->sha256, $artifact->sizeBytes, $artifact->receivedAt, $response->status,
                json_encode(['endpoint' => Api::ENDPOINT, 'query' => $query] + $extra, JSON_THROW_ON_ERROR),
            ])->fetchColumn();
            (new RawIndex($this->db,$this->raw))->register($id,$artifact->path,$artifact->sha256,$artifact->sizeBytes,$artifact->receivedAt);
            $this->db->commit();
        } catch (\Throwable) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw new AppError('RAW salvo, mas não registrado no banco. Preserve e reconcilie o arquivo: ' . $artifact->path);
        }
        return ['id' => $id, 'observed_at' => $extra['original_received_at'] ?? $artifact->receivedAt];
    }

    public function page(int $batchId, array $artifact, string $bytes, Summary $summary): array
    {
        try {
            $page = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->batches->issue($batchId, 'invalid_json', 'Resposta não contém JSON válido. RAW intacto.', $artifact['id']);
            throw new AppError('Resposta JSON inválida; RAW e problema de ingestão preservados.');
        }
        if (!$page instanceof \stdClass || !isset($page->items) || !is_array($page->items)) {
            $this->batches->issue($batchId, 'invalid_page', 'Resposta não contém a lista items esperada.', $artifact['id']);
            throw new AppError('Estrutura da página incompatível; RAW preservado.');
        }
        ++$summary->pages;
        $summary->received += count($page->items);
        $keys = [];
        foreach ($page->items as $index => $item) {
            $recordId = null;
            try {
                $payload=json_encode($item,JSON_THROW_ON_ERROR);
                $payloadHash=hash('sha256',CanonicalJson::encode($item));
                $record = Database::query($this->db, 'INSERT INTO ingestion_records(raw_artifact_id,item_index,payload,payload_sha256) VALUES (?,?,NULL,?)
                    ON CONFLICT (raw_artifact_id,item_index) DO UPDATE SET raw_artifact_id = EXCLUDED.raw_artifact_id
                    WHERE ingestion_records.payload_sha256 = EXCLUDED.payload_sha256
                        OR (ingestion_records.payload_sha256 IS NULL AND ingestion_records.payload=CAST(? AS jsonb)) RETURNING id', [
                    $artifact['id'], $index, $payloadHash, $payload,
                ])->fetchColumn();
                if ($record === false) {
                    throw new AppError('A ocorrência já registrada não corresponde ao payload informado. RAW não será reinterpretado silenciosamente.');
                }
                $recordId = (int) $record;
                $parsed = $this->parser->parse($item);
                $keys[] = $parsed->sourceKey;
                $summary->observeKey($parsed->sourceKey);
                $summary->observe($parsed->playedAt);
                $this->record($recordId, $parsed, $artifact['observed_at'], $summary);
            } catch (RecordProblem $problem) {
                $keys[] = hash('sha256', CanonicalJson::encode($item));
                $this->reject($batchId, $artifact['id'], $recordId, $problem->issueCode, $problem->getMessage(), $summary, $problem->review);
            } catch (\PDOException $error) {
                if (!str_starts_with((string) $error->getCode(), '22')) {
                    throw $error;
                }
                $keys[] = hash('sha256', CanonicalJson::encode($item));
                $this->reject($batchId, $artifact['id'], $recordId, 'unrepresentable_value', "Item {$index}: valor incompatível com PostgreSQL; consulte o RAW.", $summary);
            }
        }
        return ['page' => $page, 'count' => count($page->items), 'keys' => $keys];
    }

    private function record(int $recordId, ParsedPlay $parsed, string $observedAt, Summary $summary): void
    {
        $this->db->beginTransaction();
        try {
            Database::query($this->db, 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['spotify_api:' . $parsed->sourceKey]);
            $existing = Database::query($this->db, 'SELECT id FROM play_evidence WHERE source_id = ? AND source_record_key = ?', [$this->batches->sourceId, $parsed->sourceKey])->fetchColumn();
            if ($existing !== false) {
                $this->link($recordId, (int) $existing, $parsed->sourceKey, 'duplicate');
                $this->db->commit();
                ++$summary->existing;
                ++$summary->processed;
                return;
            }
            $trackId = $this->catalog->persist($parsed, $observedAt);
            $playId = (int) Database::query($this->db, 'INSERT INTO plays(track_id,played_at,timestamp_semantics,context_type,context_uri) VALUES (?,?,?,?,?) RETURNING id', [
                $trackId, $parsed->playedAt->format('Y-m-d\TH:i:s.uP'), 'spotify_api_played_at', $parsed->context['type'] ?? null, $parsed->context['uri'] ?? null,
            ])->fetchColumn();
            $evidenceId = Database::query($this->db, 'INSERT INTO play_evidence(play_id,source_id,source_record_key,first_ingestion_record_id) VALUES (?,?,?,?) ON CONFLICT (source_id,source_record_key) DO NOTHING RETURNING id', [
                $playId, $this->batches->sourceId, $parsed->sourceKey, $recordId,
            ])->fetchColumn();
            if ($evidenceId === false) {
                // Another writer may ignore our advisory lock. Roll back the candidate
                // play/catalog, then attach this occurrence to the winner.
                $this->db->rollBack();
                $existing = Database::query($this->db, 'SELECT id FROM play_evidence WHERE source_id = ? AND source_record_key = ?', [$this->batches->sourceId, $parsed->sourceKey])->fetchColumn();
                if ($existing === false) {
                    throw new AppError('Conflito de identidade sem evidência persistente.');
                }
                $this->link($recordId, (int) $existing, $parsed->sourceKey, 'duplicate');
                ++$summary->existing;
            } else {
                $this->link($recordId, (int) $evidenceId, $parsed->sourceKey, $parsed->partial ? 'partially_processed' : 'processed');
                $this->db->commit();
                ++$summary->inserted;
                if ($parsed->partial) {
                    ++$summary->partial;
                }
            }
            ++$summary->processed;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function link(int $recordId, int $evidenceId, string $key, string $status): void
    {
        Database::query($this->db, 'UPDATE ingestion_records SET evidence_id = ?, source_record_key = ?, processing_status = ?, normalizer_version = ?, processed_at = now() WHERE id = ?', [
            $evidenceId, $key, $status, RecentlyPlayedParser::VERSION, $recordId,
        ]);
    }

    private function reject(int $batchId, int $artifactId, ?int $recordId, string $code, string $message, Summary $summary, bool $review = false): void
    {
        if ($recordId !== null) {
            Database::query($this->db, 'UPDATE ingestion_records SET processing_status = ?, normalizer_version = ?, processed_at = now() WHERE id = ?', [
                $review ? 'pending_review' : 'rejected', RecentlyPlayedParser::VERSION, $recordId,
            ]);
        }
        $this->batches->issue($batchId, $code, $message, $artifactId, $recordId);
        ++$summary->failed;
    }
}
