<?php
declare(strict_types=1);

namespace ListenLab\Tests\Support;

use ListenLab\Http\Response;
use ListenLab\Http\Transport;

final class FakeTransport implements Transport
{
    public array $requests = [];

    public function __construct(private array $responses)
    {
    }

    public function request(string $method, string $url, array $options = []): Response
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if (!$response instanceof Response) {
            throw new \LogicException('Unexpected HTTP request in test.');
        }
        return $response;
    }
}
