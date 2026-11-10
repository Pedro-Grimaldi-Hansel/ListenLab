<?php
declare(strict_types=1);

namespace ListenLab\Tests\Unit;

use ListenLab\AppError;
use ListenLab\Http\Response;
use ListenLab\Spotify\Api;
use ListenLab\Tests\Support\FakeTokens;
use ListenLab\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    public function testEveryResponseIsCapturedBefore401RefreshAnd429Retry(): void
    {
        $http = new FakeTransport([new Response(401, '{}'), new Response(429, '{}', '2'), new Response(200, '{"items":[]}')]);
        $tokens = new FakeTokens();
        $waits = [];
        $api = new Api($http, $tokens, sleep: function (int $seconds) use (&$waits): void { $waits[] = $seconds; });
        $captured = [];
        $response = $api->page(['limit' => 50], function (Response $response) use (&$captured): void { $captured[] = $response->status; });
        self::assertSame(200, $response->status);
        self::assertSame([401, 429, 200], $captured);
        self::assertSame([2], $waits);
        self::assertSame([null, 'synthetic-access-token'], $tokens->rejected);
    }

    public function testQuotaExceededDoesNotBlindlyRetry(): void
    {
        $http = new FakeTransport([new Response(429, '{"error":{"reason":"QUOTA_EXCEEDED"}}', '1')]);
        $this->expectExceptionMessage('QUOTA_EXCEEDED');
        (new Api($http, new FakeTokens()))->page(['limit' => 50], static fn () => null);
    }

    public function testLongRetryAfterIsNotShortened(): void
    {
        $http = new FakeTransport([new Response(429, '{}', '120')]);
        $waited = false;
        try {
            (new Api($http, new FakeTokens(), sleep: function () use (&$waited): void { $waited = true; }))->page([], static fn () => null);
            self::fail('Expected 429 failure.');
        } catch (AppError) {
            self::assertFalse($waited);
            self::assertCount(1, $http->requests);
        }
    }

    public function testAcceptsOnlyDocumentedPaginationParameters(): void
    {
        self::assertSame(['before' => '1794294000000', 'limit' => '50'], Api::nextQuery(Api::ENDPOINT . '?before=1794294000000&limit=50'));
    }

    #[DataProvider('unsafeUrls')]
    public function testPaginationNeverForwardsBearerToAnUntrustedUrl(string $url): void
    {
        $this->expectException(AppError::class);
        Api::nextQuery($url);
    }

    public static function unsafeUrls(): array
    {
        return [
            ['https://example.test/?before=1'], ['http://api.spotify.com/v1/me/player/recently-played'],
            [Api::ENDPOINT . '?before=1&after=2'], [Api::ENDPOINT . '?limit=51'],
            [Api::ENDPOINT . '?before[]=1'], [Api::ENDPOINT . '?access_token=secret'],
            ['https://user@api.spotify.com/v1/me/player/recently-played'],
        ];
    }
}
