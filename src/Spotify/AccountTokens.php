<?php
declare(strict_types=1);

namespace ListenLab\Spotify;

final class AccountTokens implements TokenProvider
{
    public function __construct(private readonly TokenProvider $tokens, private readonly AccountGuard $accounts) {}

    public function accessToken(?string $rejectedToken = null): string
    {
        $token = $this->tokens->accessToken($rejectedToken);
        try {
            $this->accounts->verify($token);
        } catch (AuthorizationExpired) {
            $token = $this->tokens->accessToken($token);
            $this->accounts->verify($token);
        }
        return $token;
    }
}
