<?php
declare(strict_types=1);

namespace ListenLab\Spotify;

use ListenLab\AppError;
use ListenLab\Config;
use ListenLab\Http\Transport;
use ListenLab\Storage\PrivateJsonStore;

final class OAuth implements TokenProvider
{
    public const SCOPE = 'user-read-recently-played';
    private readonly PrivateJsonStore $tokens;
    private readonly PrivateJsonStore $states;
    private readonly ?\Closure $verifyAccount;

    public function __construct(private readonly Config $config, private readonly Transport $http, ?callable $verifyAccount = null)
    {
        $this->verifyAccount = $verifyAccount !== null ? $verifyAccount(...) : null;
        $directory = $config->path('TOKEN_STORAGE_PATH', 'tokens');
        $this->tokens = new PrivateJsonStore($directory, 'spotify');
        $this->states = new PrivateJsonStore($directory, 'oauth-state');
    }

    public function begin(): string
    {
        $this->config->requireSpotify();
        $state = bin2hex(random_bytes(32));
        $this->states->locked(fn (PrivateJsonStore $store) => $store->write([
            'hash' => hash('sha256', $state), 'expires_at' => time() + 600,
            'redirect_uri' => $this->config->required('SPOTIFY_REDIRECT_URI'),
            'client_id' => $this->config->required('SPOTIFY_CLIENT_ID'),
        ]));
        return 'https://accounts.spotify.com/authorize?' . http_build_query([
            'client_id' => $this->config->required('SPOTIFY_CLIENT_ID'),
            'response_type' => 'code',
            'redirect_uri' => $this->config->required('SPOTIFY_REDIRECT_URI'),
            'scope' => self::SCOPE, 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function completeUrl(string $url): void
    {
        $parts = parse_url($url);
        $configured = parse_url($this->config->required('SPOTIFY_REDIRECT_URI'));
        foreach (['scheme', 'host', 'port', 'path'] as $part) {
            if (($parts[$part] ?? null) !== ($configured[$part] ?? null)) {
                throw new AppError('O callback não corresponde ao SPOTIFY_REDIRECT_URI configurado.');
            }
        }
        parse_str($parts['query'] ?? '', $query);
        $this->complete($query);
    }

    public function complete(array $query): void
    {
        $this->config->requireSpotify();
        $this->states->locked(function (PrivateJsonStore $store) use ($query): void {
            $pending = $store->read();
            $state = $query['state'] ?? null;
            if (!$pending || !is_string($state) || !hash_equals($pending['hash'], hash('sha256', $state))
                || $pending['expires_at'] < time() || $pending['client_id'] !== $this->config->required('SPOTIFY_CLIENT_ID')
                || $pending['redirect_uri'] !== $this->config->required('SPOTIFY_REDIRECT_URI')) {
                throw new AppError('OAuth state inválido ou expirado. Execute spotify:auth novamente.');
            }
            $store->delete(); // A state is single-use, including denied authorizations.
        });
        if (isset($query['error'])) {
            throw new AppError('Autorização Spotify não concedida. Execute spotify:auth para tentar novamente.');
        }
        if (!is_string($query['code'] ?? null) || $query['code'] === '') {
            throw new AppError('Callback sem código de autorização.');
        }
        $token = $this->exchange([
            'grant_type' => 'authorization_code', 'code' => $query['code'],
            'redirect_uri' => $this->config->required('SPOTIFY_REDIRECT_URI'),
        ]);
        if ($this->verifyAccount !== null) ($this->verifyAccount)($token['access_token']);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $token['authorized_at'] = $now->format(DATE_ATOM);
        $token['reauthorize_due_at'] = $now->modify('+6 months')->format(DATE_ATOM);
        $token['client_id'] = $this->config->required('SPOTIFY_CLIENT_ID');
        $this->tokens->locked(fn (PrivateJsonStore $store) => $store->write($token));
    }

    public function accessToken(?string $rejectedToken = null): string
    {
        $this->config->requireSpotify();
        return $this->tokens->locked(function (PrivateJsonStore $store) use ($rejectedToken): string {
            $token = $store->read();
            if (!$token || ($token['client_id'] ?? null) !== $this->config->required('SPOTIFY_CLIENT_ID')) {
                throw new AppError('Autorização ausente para este aplicativo. Execute php app spotify:auth.');
            }
            if ($token['expires_at'] > time() + 60 && ($rejectedToken === null || !hash_equals($token['access_token'], $rejectedToken))) {
                return $token['access_token'];
            }
            try {
                $fresh = $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $token['refresh_token']], $token);
            } catch (AuthorizationExpired $error) {
                $store->delete();
                throw $error;
            }
            $store->write(array_replace($token, $fresh));
            return $fresh['access_token'];
        });
    }

    public function status(): array
    {
        return $this->tokens->locked(function (PrivateJsonStore $store): array {
            $token = $store->read();
            return $token ? array_intersect_key($token, array_flip(['authorized_at','reauthorize_due_at','scope','expires_at'])) : ['authorized' => false];
        });
    }

    private function exchange(array $form, array $previous = []): array
    {
        $response = $this->http->request('POST', 'https://accounts.spotify.com/api/token', [
            'auth' => [$this->config->required('SPOTIFY_CLIENT_ID'), $this->config->required('SPOTIFY_CLIENT_SECRET')],
            'form_params' => $form,
        ]);
        // Credential responses NEVER enter the music RAW store or logs.
        $payload = json_decode($response->body, true);
        if (($payload['error'] ?? null) === 'invalid_grant') {
            throw new AuthorizationExpired('Autorização expirada ou revogada. Execute php app spotify:auth novamente.');
        }
        if ($response->status !== 200) {
            throw new AppError('Falha no endpoint de tokens Spotify (HTTP ' . $response->status . '). Verifique configuração e autorização.');
        }
        $refresh = $payload['refresh_token'] ?? $previous['refresh_token'] ?? null;
        if (!is_string($payload['access_token'] ?? null) || $payload['access_token'] === '' || !is_string($refresh) || $refresh === ''
            || !is_int($payload['expires_in'] ?? null) || $payload['expires_in'] <= 0 || ($payload['token_type'] ?? '') !== 'Bearer') {
            throw new AppError('Resposta inválida do endpoint de tokens Spotify.');
        }
        $scope = $payload['scope'] ?? $previous['scope'] ?? self::SCOPE;
        if (!is_string($scope) || !in_array(self::SCOPE, explode(' ', $scope), true)) {
            throw new AppError('Scope user-read-recently-played ausente. Autorize novamente.');
        }
        return [
            'access_token' => $payload['access_token'], 'refresh_token' => $refresh,
            'expires_at' => time() + $payload['expires_in'], 'scope' => $scope,
        ];
    }
}
