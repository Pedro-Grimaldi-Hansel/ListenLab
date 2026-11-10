<?php
declare(strict_types=1);

namespace ListenLab\Tests\Support;

use ListenLab\Spotify\TokenProvider;

final class FakeTokens implements TokenProvider
{
    public array $rejected = [];

    public function accessToken(?string $rejectedToken = null): string
    {
        $this->rejected[] = $rejectedToken;
        return $rejectedToken === null ? 'synthetic-access-token' : 'synthetic-refreshed-token';
    }
}
