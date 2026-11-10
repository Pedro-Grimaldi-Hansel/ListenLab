<?php
declare(strict_types=1);

namespace ListenLab\Spotify;

interface TokenProvider
{
    public function accessToken(?string $rejectedToken = null): string;
}
