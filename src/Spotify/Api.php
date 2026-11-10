<?php
declare(strict_types=1);

namespace ListenLab\Spotify;

use ListenLab\AppError;
use ListenLab\Http\Response;
use ListenLab\Http\Transport;

final class Api
{
    public const ENDPOINT = 'https://api.spotify.com/v1/me/player/recently-played';
    private readonly \Closure $sleep;

    public function __construct(
        private readonly Transport $http,
        private readonly TokenProvider $tokens,
        private readonly int $maxRetries = 2,
        private readonly int $maxWaitSeconds = 30,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep !== null ? $sleep(...) : static fn (int $seconds) => sleep($seconds);
    }

    public function page(array $query, callable $capture): Response
    {
        $token = $this->tokens->accessToken();
        $refreshed = false;
        $retries = 0;
        while (true) {
            $response = $this->http->request('GET', self::ENDPOINT, [
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                'query' => $query,
            ]);
            $capture($response, $query); // Preserve before parsing, retries or validation.
            if ($response->status === 401 && !$refreshed) {
                $token = $this->tokens->accessToken($token);
                $refreshed = true;
                continue;
            }
            if ($response->status === 429) {
                $payload = json_decode($response->body, true);
                if (($payload['error']['reason'] ?? null) === 'QUOTA_EXCEEDED') {
                    throw new AppError('Quota Spotify excedida (QUOTA_EXCEEDED). RAW preservado; execute novamente após a liberação da quota.');
                }
                $wait = $response->retryAfter;
                if (!is_string($wait) || !ctype_digit($wait) || (int) $wait > $this->maxWaitSeconds || $retries >= $this->maxRetries) {
                    throw new AppError('Spotify retornou 429. Respeite Retry-After; espera ausente, longa ou tentativas esgotadas. RAW preservado.');
                }
                ($this->sleep)((int) $wait);
                ++$retries;
                continue;
            }
            if ($response->status !== 200) {
                throw new AppError('Spotify HTTP ' . $response->status . '. RAW preservado. Em 401, reautorize; em 403, confira Premium e allowlist do Dashboard.');
            }
            return $response;
        }
    }

    public static function nextQuery(string $url): array
    {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'api.spotify.com'
            || ($parts['path'] ?? '') !== '/v1/me/player/recently-played' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new AppError('URL de paginação incompatível. Coleta interrompida sem encaminhar credenciais.');
        }
        parse_str($parts['query'] ?? '', $query);
        foreach ($query as $key => $value) {
            if (!in_array($key, ['limit','before','after'], true) || !is_string($value) || !ctype_digit($value)) {
                throw new AppError('Parâmetros de paginação incompatíveis.');
            }
        }
        if (isset($query['before'], $query['after']) || (isset($query['limit']) && ((int) $query['limit'] < 1 || (int) $query['limit'] > 50))) {
            throw new AppError('Parâmetros de paginação inválidos.');
        }
        return $query;
    }
}
