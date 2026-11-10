<?php
declare(strict_types=1);

namespace ListenLab\Tests\Support;

use ListenLab\Http\Response;

final class Fixtures
{
    public static function item(string $playedAt = '2026-11-10T07:00:00.000Z', string $id = 'SyntheticTrackA00000001'): \stdClass
    {
        return json_decode(json_encode([
            'played_at' => $playedAt,
            'track' => [
                'type' => 'track', 'id' => $id, 'uri' => 'spotify:track:' . $id,
                'name' => 'Faixa de teste ' . $id, 'duration_ms' => 180000, 'is_local' => false,
                'album' => ['id' => 'SyntheticAlbum00000001', 'uri' => 'spotify:album:SyntheticAlbum00000001', 'name' => 'Álbum sintético', 'release_date' => '2024', 'release_date_precision' => 'year'],
                'artists' => [
                    ['id' => 'SyntheticArtist0000001', 'uri' => 'spotify:artist:SyntheticArtist0000001', 'name' => 'Artista fictício A'],
                    ['id' => 'SyntheticArtist0000002', 'uri' => 'spotify:artist:SyntheticArtist0000002', 'name' => 'Artista fictício B'],
                ],
                'external_ids' => ['isrc' => 'ZZTEST0000001'],
            ],
            'context' => ['type' => 'playlist', 'uri' => 'spotify:playlist:SyntheticPlaylist00001'],
            'unknown_future_field' => ['preserve' => true],
        ], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    public static function items(): array
    {
        return [self::item(), self::item('2026-11-10T07:04:00Z', 'SyntheticTrackB00000001'), self::item('2026-11-10T07:08:00Z')];
    }

    public static function body(?array $items = null, ?string $next = null, ?string $before = null): string
    {
        return json_encode([
            'items' => $items ?? self::items(), 'next' => $next,
            'cursors' => ['before' => $before, 'after' => '1794294480000'], 'limit' => 50,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    public static function response(?array $items = null, ?string $next = null, ?string $before = null): Response
    {
        return new Response(200, self::body($items, $next, $before));
    }
}
