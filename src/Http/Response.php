<?php
declare(strict_types=1);

namespace ListenLab\Http;

final readonly class Response
{
    public function __construct(public int $status, public string $body, public ?string $retryAfter = null)
    {
    }
}
