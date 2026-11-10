<?php
declare(strict_types=1);

namespace ListenLab\Ingestion;

final class RecentlyPlayedParser
{
    public const VERSION = 'spotify-recently-played-v1';
    public const KEY_VERSION = 'spotify-api-source-key-v1';

    public function parse(mixed $item): ParsedPlay
    {
        if (!$item instanceof \stdClass) {
            throw new RecordProblem('invalid_item', 'Item de histórico não é um objeto JSON.');
        }
        $value = json_decode(json_encode($item, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $playedAt = self::timestamp($value['played_at'] ?? null);
        $track = $this->object($value['track'] ?? null, 'track');
        if (!$track) {
            throw new RecordProblem('missing_track', 'Objeto track ausente ou vazio.');
        }
        $type = $this->string($track, 'type');
        if ($type !== null && $type !== 'track') {
            throw new RecordProblem('unsupported_media', 'Tipo de mídia ainda não normalizado; RAW preservado.', true);
        }
        $normalized = [
            'external_id' => $this->string($track, 'id'), 'external_uri' => $this->string($track, 'uri'),
            'name' => $this->string($track, 'name'), 'duration_ms' => $this->integer($track, 'duration_ms'),
            'explicit' => $this->boolean($track, 'explicit'), 'is_local' => $this->boolean($track, 'is_local'),
        ];
        if ($normalized['external_id'] === '' || $normalized['external_uri'] === '') {
            throw new RecordProblem('invalid_identity', 'Identificador externo vazio. Use NULL para identidade desconhecida.');
        }
        if ($normalized['external_id'] !== null && $normalized['external_uri'] !== null
            && preg_match('/^spotify:track:(.+)$/D', $normalized['external_uri'], $matches)
            && $matches[1] !== $normalized['external_id']) {
            throw new RecordProblem('inconsistent_identity', 'Track ID e URI Spotify incompatíveis.');
        }
        if ($normalized['external_id'] === null && $normalized['external_uri'] === null && $normalized['name'] === null) {
            throw new RecordProblem('missing_identity', 'Track sem identidade externa nem metadados mínimos.');
        }
        $external = $this->object($track['external_ids'] ?? null, 'external_ids');
        $linked = $this->object($track['linked_from'] ?? null, 'linked_from');
        $normalized['isrc'] = $external !== null ? $this->string($external, 'isrc') : null;
        $normalized['linked_from_id'] = $linked !== null ? $this->string($linked, 'id') : null;
        $normalized['linked_from_uri'] = $linked !== null ? $this->string($linked, 'uri') : null;

        $album = $this->object($track['album'] ?? null, 'album');
        if ($album !== null) {
            $album = [
                'external_id' => $this->string($album, 'id'), 'external_uri' => $this->string($album, 'uri'),
                'name' => $this->string($album, 'name'), 'release_date' => $this->string($album, 'release_date'),
                'release_date_precision' => $this->string($album, 'release_date_precision'),
            ];
            $this->validateRelease($album);
        }
        $artists = null;
        if (array_key_exists('artists', $track) && $track['artists'] !== null) {
            if (!is_array($track['artists']) || !array_is_list($track['artists'])) {
                throw new RecordProblem('invalid_artists', 'artists não é uma lista.');
            }
            $artists = [];
            foreach ($track['artists'] as $artist) {
                $artist = $this->object($artist, 'artist');
                if ($artist === null) {
                    throw new RecordProblem('invalid_artist', 'Artista não é um objeto.');
                }
                $artists[] = ['external_id' => $this->string($artist, 'id'), 'external_uri' => $this->string($artist, 'uri'), 'name' => $this->string($artist, 'name')];
            }
        }
        $context = $this->object($value['context'] ?? null, 'context');
        if ($context !== null) {
            $context = ['type' => $this->string($context, 'type'), 'uri' => $this->string($context, 'uri')];
        }
        $identity = $normalized['external_id'] !== null
            ? ['spotify_track_id', $normalized['external_id']]
            : ($normalized['external_uri'] !== null ? ['spotify_uri', $normalized['external_uri']] : ['unidentified_payload', CanonicalJson::encode($item)]);
        $key = hash('sha256', CanonicalJson::encode([self::KEY_VERSION, $playedAt->format('Y-m-d\TH:i:s.u\Z'), $identity]));
        $partial = $normalized['external_id'] === null || $normalized['name'] === null || $album === null || $artists === null || $artists === [];
        return new ParsedPlay($key, $playedAt, $normalized, $album, $artists, $context, $partial);
    }

    public static function timestamp(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $value)) {
            throw new RecordProblem('invalid_timestamp', 'played_at deve ser ISO 8601 com timezone explícito.');
        }
        try {
            $date = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new \InvalidArgumentException();
            }
            return $date->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new RecordProblem('invalid_timestamp', 'played_at contém uma data impossível.');
        }
    }

    private function object(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RecordProblem('invalid_structure', "{$field} não é um objeto.");
        }
        return $value;
    }

    private function string(array $value, string $key): ?string
    {
        if (!isset($value[$key])) {
            return null;
        }
        if (!is_string($value[$key])) {
            throw new RecordProblem('invalid_field_type', "{$key} não é string ou NULL.");
        }
        return $value[$key];
    }

    private function integer(array $value, string $key): ?int
    {
        if (!isset($value[$key])) {
            return null;
        }
        if (!is_int($value[$key]) || $value[$key] < 0 || $value[$key] > 2147483647) {
            throw new RecordProblem('invalid_field_type', "{$key} deve ser inteiro não negativo de 32 bits ou NULL.");
        }
        return $value[$key];
    }

    private function boolean(array $value, string $key): ?bool
    {
        if (!isset($value[$key])) {
            return null;
        }
        if (!is_bool($value[$key])) {
            throw new RecordProblem('invalid_field_type', "{$key} não é boolean ou NULL.");
        }
        return $value[$key];
    }

    private function validateRelease(array $album): void
    {
        $date = $album['release_date'];
        $precision = $album['release_date_precision'];
        if ($date === null && $precision === null) {
            return;
        }
        if ($date === null || ($precision !== null && !in_array($precision, ['year','month','day'], true))) {
            throw new RecordProblem('invalid_release_date', 'Data/precisão de lançamento incompatíveis.');
        }
        $valid = match ($precision) {
            'year' => (bool) preg_match('/^\d{4}$/D', $date),
            'month' => (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $date),
            'day' => (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]),
            null => (bool) preg_match('/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/D', $date),
        };
        if (!$valid) {
            throw new RecordProblem('invalid_release_date', 'Data de lançamento não corresponde à precisão informada.');
        }
    }
}
