<?php
declare(strict_types=1);

namespace ListenLab\Http;

interface Transport
{
    public function request(string $method, string $url, array $options = []): Response;
}
