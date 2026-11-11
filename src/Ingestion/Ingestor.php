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
        // Even items omitted from the detailed audit remain bound to immutable RAW bytes.
        $expected = Database::query($this->db, 'SELECT sha256 FROM raw_artifacts WHERE id = ?', [$artifact['id']])->fetchColumn();
        if (!is_string($expected) || !hash_equals($expected, hash('sha256', $bytes))) {
            throw new AppError('O conteúdo não corresponde ao payload do RAW registrado.');
        }
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
            $payloadHash = hash('sha256', CanonicalJson::encode($item));
            try {
                $this->validateStorageStrings($item, $index);
                $parsed = $this->parser->parse($item);
                $keys[] = $parsed->sourceKey;
                $summary->observeKey($parsed->sourceKey);
                $summary->observe($parsed->playedAt);
                $this->record($artifact, $index, $payloadHash, $parsed, $summary);
            } catch (RecordProblem $problem) {
                [$recordId, $created] = $this->occurrence((int) $artifact['id'], $index, $payloadHash);
                $summary->auditCreated += (int) $created;
                $keys[] = $payloadHash;
                $this->reject($batchId, $artifact['id'], $recordId, $problem->issueCode, $problem->getMessage(), $summary, $problem->review);
            } catch (\PDOException $error) {
                if (!str_starts_with((string) $error->getCode(), '22')) {
                    throw $error;
                }
                [$recordId, $created] = $this->occurrence((int) $artifact['id'], $index, $payloadHash);
                $summary->auditCreated += (int) $created;
                $keys[] = $payloadHash;
                $this->reject($batchId, $artifact['id'], $recordId, 'unrepresentable_value', "Item {$index}: valor incompatível com PostgreSQL; consulte o RAW.", $summary);
            }
        }
        return ['page' => $page, 'count' => count($page->items), 'keys' => $keys];
    }

    private function validateStorageStrings(mixed $value, int $index): void
    {
        // JSON permits U+0000, PostgreSQL text/JSONB does not. Keep the existing
        // rejection policy even though new audit rows now store only hashes.
        if (is_string($value) && str_contains($value, "\0")) {
            throw new RecordProblem('unrepresentable_value', "Item {$index}: valor incompatível com PostgreSQL; consulte o RAW.");
        }
        if (is_object($value) || is_array($value)) {
            foreach ($value as $key => $child) {
                $this->validateStorageStrings($key, $index);
                $this->validateStorageStrings($child, $index);
            }
        }
    }

    private function record(array $artifact, int $index, string $payloadHash, ParsedPlay $parsed, Summary $summary): void
    {
        // Serialize play creation and the decision to omit a known payload together.
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $this->db->beginTransaction();
            try {
                Database::query($this->db, 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['spotify_api:' . $parsed->sourceKey]);
                $existing = Database::query($this->db, 'SELECT id,first_ingestion_record_id FROM play_evidence WHERE source_id = ? AND source_record_key = ?', [$this->batches->sourceId, $parsed->sourceKey])->fetch(PDO::FETCH_ASSOC);
                if ($existing && !$parsed->partial && $this->knownPayload($existing, $parsed->sourceKey, $payloadHash)) {
                    $this->db->commit();
                    ++$summary->existing;
                    ++$summary->processed;
                    ++$summary->duplicatesOmitted;
                    return;
                }
                [$recordId, $created] = $this->occurrence((int) $artifact['id'], $index, $payloadHash);
                if ($existing) {
                    $this->link($recordId, (int) $existing['id'], $parsed->sourceKey, 'duplicate');
                } else {
                    $trackId = $this->catalog->persist($parsed, $artifact['observed_at']);
                    $playId = (int) Database::query($this->db, 'INSERT INTO plays(track_id,played_at,timestamp_semantics,context_type,context_uri) VALUES (?,?,?,?,?) RETURNING id', [
                        $trackId, $parsed->playedAt->format('Y-m-d\TH:i:s.uP'), 'spotify_api_played_at', $parsed->context['type'] ?? null, $parsed->context['uri'] ?? null,
                    ])->fetchColumn();
                    $evidenceId = Database::query($this->db, 'INSERT INTO play_evidence(play_id,source_id,source_record_key,first_ingestion_record_id) VALUES (?,?,?,?) ON CONFLICT (source_id,source_record_key) DO NOTHING RETURNING id', [
                        $playId, $this->batches->sourceId, $parsed->sourceKey, $recordId,
                    ])->fetchColumn();
                    if ($evidenceId === false) {
                        // A writer outside our lock won the constraint. Retry after
                        // rolling back the candidate catalog, play and audit record.
                        $this->db->rollBack();
                        continue;
                    }
                    $this->link($recordId, (int) $evidenceId, $parsed->sourceKey, $parsed->partial ? 'partially_processed' : 'processed');
                }
                $this->db->commit();
                $summary->auditCreated += (int) $created;
                if ($existing) ++$summary->existing;
                else {
                    ++$summary->inserted;
                }
                if ($parsed->partial) ++$summary->partial;
                ++$summary->processed;
                return;
            } catch (\Throwable $error) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $error;
            }
        }
        throw new AppError('Conflito de identidade sem evidência persistente.');
    }

    private function knownPayload(array $evidence, string $sourceKey, string $hash): bool
    {
        $known = Database::query($this->db, "SELECT EXISTS(SELECT 1 FROM ingestion_records
            WHERE source_record_key=? AND evidence_id=? AND payload_sha256=? AND normalizer_version=?
            AND processing_status IN ('processed','duplicate'))", [$sourceKey, $evidence['id'], $hash, RecentlyPlayedParser::VERSION])->fetchColumn();
        if ($known) return true;
        // The legacy first occurrence can prove equality without rewriting JSONB
        // or creating an extra row just to populate its hash column.
        $first = Database::query($this->db, 'SELECT payload,normalizer_version,processing_status FROM ingestion_records WHERE id=? AND payload_sha256 IS NULL', [$evidence['first_ingestion_record_id']])->fetch(PDO::FETCH_ASSOC);
        return $first && $first['payload'] !== null && $first['normalizer_version'] === RecentlyPlayedParser::VERSION
            && $first['processing_status'] === 'processed'
            && hash_equals($hash, hash('sha256', CanonicalJson::encode(json_decode($first['payload'], false, 512, JSON_THROW_ON_ERROR))));
    }

    /** @return array{int,bool} Existing rows remain available; no row is deleted. */
    private function occurrence(int $artifactId, int $index, string $hash): array
    {
        $id = Database::query($this->db, 'INSERT INTO ingestion_records(raw_artifact_id,item_index,payload,payload_sha256) VALUES (?,?,NULL,?)
            ON CONFLICT (raw_artifact_id,item_index) DO NOTHING RETURNING id', [$artifactId, $index, $hash])->fetchColumn();
        if ($id !== false) return [(int) $id, true];
        $row = Database::query($this->db, 'SELECT id,payload,payload_sha256 FROM ingestion_records WHERE raw_artifact_id=? AND item_index=?', [$artifactId, $index])->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $previous = $row['payload_sha256'] ?? hash('sha256', CanonicalJson::encode(json_decode($row['payload'], false, 512, JSON_THROW_ON_ERROR)));
            if (hash_equals($hash, $previous)) return [(int) $row['id'], false];
        }
        throw new AppError('A ocorrência já registrada não corresponde ao payload informado. RAW não será reinterpretado silenciosamente.');
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
