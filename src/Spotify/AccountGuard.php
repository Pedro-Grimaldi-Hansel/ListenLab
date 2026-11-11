<?php
declare(strict_types=1);

namespace ListenLab\Spotify;

use ListenLab\AppError;
use ListenLab\Database;
use ListenLab\Http\Transport;
use PDO;

final class AccountGuard
{
    public const PROFILE_ENDPOINT = 'https://api.spotify.com/v1/me';

    public function __construct(private readonly PDO $db, private readonly Transport $http) {}

    /** Only the explicit initial-upgrade CLI may bind an already populated dataset. */
    public function verify(string $accessToken, bool $bindExistingDataset = false): void
    {
        $tokenHash = hash('sha256', $accessToken);
        $binding = $this->binding();
        if ($binding && hash_equals($binding['verified_token_sha256'], $tokenHash)) return;
        if (!$binding && !$bindExistingDataset && $this->hasPlays()) {
            throw new AppError('Vincule a conta do token atual com spotify:account:bind antes de coletar ou reautorizar este histórico.');
        }
        if ($accessToken === '') throw new AppError('Token ausente ao verificar a conta Spotify.');
        $response = $this->http->request('GET', self::PROFILE_ENDPOINT, [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'],
        ]);
        // Profile fields and tokens are never stored in music RAW or error messages.
        if ($response->status === 401) throw new AuthorizationExpired('Token recusado ao verificar a conta Spotify. Renove a autorização se a falha persistir.');
        if ($response->status !== 200) throw new AppError('Não foi possível verificar a conta Spotify (HTTP ' . $response->status . '). Nenhum evento novo foi aceito.');
        $profile = json_decode($response->body, true);
        $account = is_array($profile) ? ($profile['account_id'] ?? null) : null;
        if (!is_string($account) || $account === '' || strlen($account) > 512) throw new AppError('Perfil Spotify sem account_id estável. A conta não será inferida de nome, e-mail ou id legado.');
        $accountHash = hash('sha256', $account);
        if ($this->db->inTransaction()) throw new AppError('Verifique a conta fora de uma transação de ingestão.');
        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT pg_advisory_xact_lock(194701, 5)');
            $binding = $this->binding();
            if ($binding && !hash_equals($binding['account_sha256'], $accountHash)) {
                throw new AppError('Esta conta Spotify é diferente da conta vinculada ao histórico. Autorização e dados anteriores foram preservados.');
            }
            if (!$binding && !$bindExistingDataset && $this->hasPlays()) throw new AppError('O histórico existente exige spotify:account:bind com o token atual.');
            if (!$binding) {
                Database::query($this->db, 'INSERT INTO spotify_account_binding(account_sha256,verified_token_sha256) VALUES (?,?)', [$accountHash, $tokenHash]);
            } else {
                Database::query($this->db, 'UPDATE spotify_account_binding SET verified_token_sha256=?,verified_at=now() WHERE singleton', [$tokenHash]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function binding(): array|false
    {
        return $this->db->query('SELECT account_sha256,verified_token_sha256 FROM spotify_account_binding WHERE singleton')->fetch(PDO::FETCH_ASSOC);
    }

    private function hasPlays(): bool { return (bool)$this->db->query('SELECT EXISTS(SELECT 1 FROM plays)')->fetchColumn(); }
}
