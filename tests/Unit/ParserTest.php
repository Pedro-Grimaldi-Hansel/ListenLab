<?php
declare(strict_types=1);

namespace ListenLab\Tests\Unit;

use ListenLab\Ingestion\CanonicalJson;
use ListenLab\Ingestion\RecentlyPlayedParser;
use ListenLab\Ingestion\RecordProblem;
use ListenLab\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function testKeyIgnoresMetadataChangesAndObjectKeyOrder(): void
    {
        $parser = new RecentlyPlayedParser();
        $item = Fixtures::item();
        $expected = $parser->parse($item)->sourceKey;
        $item->track->name = 'Nome atualizado';
        $item->context = null;
        $item->played_at = '2026-11-10T04:00:00-03:00';
        self::assertSame($expected, $parser->parse($item)->sourceKey);
        self::assertSame(CanonicalJson::encode((object) ['b' => 2, 'a' => 1]), CanonicalJson::encode((object) ['a' => 1, 'b' => 2]));
    }

    public function testReplayAtDifferentTimestampProducesDifferentKey(): void
    {
        $parser = new RecentlyPlayedParser();
        self::assertNotSame($parser->parse(Fixtures::item())->sourceKey, $parser->parse(Fixtures::item('2026-11-10T07:04:00Z'))->sourceKey);
    }

    public function testDifferentSpotifyIdsStaySeparate(): void
    {
        $parser = new RecentlyPlayedParser();
        self::assertNotSame($parser->parse(Fixtures::item())->sourceKey, $parser->parse(Fixtures::item(id: 'SyntheticOtherVersion1'))->sourceKey);
    }

    public function testMissingValuesRemainNullAndMultipleArtistsKeepOrder(): void
    {
        $play = (new RecentlyPlayedParser())->parse(Fixtures::item());
        self::assertNull($play->track['explicit']);
        self::assertFalse($play->track['is_local']);
        self::assertSame(['Artista fictício A', 'Artista fictício B'], array_column($play->artists, 'name'));
        self::assertSame('2024', $play->album['release_date']);
        self::assertSame('year', $play->album['release_date_precision']);
    }

    public function testLocalTrackDoesNotRequireSpotifyId(): void
    {
        $item = Fixtures::item();
        $item->track->id = null;
        $item->track->uri = 'spotify:local:Ficticio:Disco:Faixa:180';
        $item->track->is_local = true;
        $parsed = (new RecentlyPlayedParser())->parse($item);
        self::assertNull($parsed->track['external_id']);
        self::assertTrue($parsed->partial);
        self::assertTrue($parsed->track['is_local']);
    }

    public function testNoExternalIdentityUsesStableConservativePayloadFingerprint(): void
    {
        $item = Fixtures::item();
        $item->track->id = null;
        $item->track->uri = null;
        $parser = new RecentlyPlayedParser();
        $key = $parser->parse($item)->sourceKey;
        self::assertSame($key, $parser->parse(unserialize(serialize($item)))->sourceKey);
        $item->track->name = 'Outro nome';
        self::assertNotSame($key, $parser->parse($item)->sourceKey);
    }

    #[DataProvider('invalidDates')]
    public function testRejectsInvalidTimestamps(mixed $date): void
    {
        $this->expectException(RecordProblem::class);
        RecentlyPlayedParser::timestamp($date);
    }

    public static function invalidDates(): array
    {
        return [[null], ['2026-11-10 07:00:00'], ['2026-02-30T07:00:00Z'], ['2026-11-10T99:00:00Z'], ['2026-11-10T07:00:00+99:00']];
    }

    public function testMalformedBooleanIsRejectedInsteadOfCoerced(): void
    {
        $item = Fixtures::item();
        $item->track->is_local = 'false';
        $this->expectException(RecordProblem::class);
        (new RecentlyPlayedParser())->parse($item);
    }

    public function testPodcastRequiresReview(): void
    {
        $item = Fixtures::item();
        $item->track->type = 'episode';
        try {
            (new RecentlyPlayedParser())->parse($item);
            self::fail('Expected pending review.');
        } catch (RecordProblem $problem) {
            self::assertTrue($problem->review);
            self::assertSame('unsupported_media', $problem->issueCode);
        }
    }

    public function testInconsistentTrackIdentityIsRejected(): void
    {
        $item = Fixtures::item();
        $item->track->uri = 'spotify:track:DifferentId';
        $this->expectException(RecordProblem::class);
        (new RecentlyPlayedParser())->parse($item);
    }
}
