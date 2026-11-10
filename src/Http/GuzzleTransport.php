<?php
declare(strict_types=1);

namespace ListenLab\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use ListenLab\AppError;

final class GuzzleTransport implements Transport
{
    private readonly Client $client;

    public function __construct()
    {
        $this->client = new Client([
            // Honor the inherited combined CA bundle in managed environments.
            // With no override, use the system trust store. TLS stays enabled.
            'verify' => ($_ENV['SSL_CERT_FILE'] ?? getenv('SSL_CERT_FILE')) ?: true,
            'http_errors' => false,
            'allow_redirects' => false,
            'connect_timeout' => 10,
            'timeout' => 30,
        ]);
    }

    public function request(string $method, string $url, array $options = []): Response
    {
        try {
            $response = $this->client->request($method, $url, $options);
            return new Response($response->getStatusCode(), (string) $response->getBody(), $response->getHeaderLine('Retry-After') ?: null);
        } catch (GuzzleException) {
            // Library exceptions can include request headers, URLs and credentials.
            throw new AppError('Falha de transporte HTTPS. Nenhum detalhe de credenciais foi registrado.');
        }
    }
}
