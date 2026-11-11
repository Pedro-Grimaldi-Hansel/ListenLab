<?php
declare(strict_types=1);

namespace ListenLab\Tests\Unit;

use ListenLab\AppError;
use ListenLab\Config;
use ListenLab\Http\Response;
use ListenLab\Spotify\AuthorizationExpired;
use ListenLab\Spotify\OAuth;
use ListenLab\Storage\PrivateJsonStore;
use ListenLab\Tests\Support\FakeTransport;
use ListenLab\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class OAuthTest extends TestCase
{
    use TemporaryDirectory;
    private Config $config;

    protected function setUp(): void
    {
        $this->createTemporary();
        $this->config = new Config($this->temporary, [
            'SPOTIFY_CLIENT_ID' => 'synthetic-client', 'SPOTIFY_CLIENT_SECRET' => 'synthetic-secret',
            'SPOTIFY_REDIRECT_URI' => 'http://127.0.0.1:8888/spotify/callback',
        ]);
    }

    protected function tearDown(): void { $this->removeTemporary(); }

    private function tokenResponse(array $extra = []): Response
    {
        return new Response(200, json_encode($extra + [
            'access_token' => 'synthetic-token', 'refresh_token' => 'synthetic-refresh',
            'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => OAuth::SCOPE,
        ], JSON_THROW_ON_ERROR));
    }

    private function authorize(OAuth $oauth): void
    {
        parse_str(parse_url($oauth->begin(), PHP_URL_QUERY), $query);
        self::assertSame(OAuth::SCOPE, $query['scope']);
        $oauth->completeUrl($this->config->required('SPOTIFY_REDIRECT_URI') . '?' . http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']));
    }

    public function testAuthorizationPersistsSecretsPrivatelyAndOnlyExposesDatesInStatus(): void
    {
        $oauth = new OAuth($this->config, new FakeTransport([$this->tokenResponse()]));
        $this->authorize($oauth);
        self::assertSame('synthetic-token', $oauth->accessToken());
        self::assertSame(0600, fileperms($this->temporary . '/tokens/spotify.json') & 0777);
        $status = $oauth->status();
        self::assertArrayHasKey('authorized_at', $status);
        self::assertArrayHasKey('reauthorize_due_at', $status);
        self::assertArrayNotHasKey('access_token', $status);
        self::assertArrayNotHasKey('refresh_token', $status);
    }

    public function testStateMustMatchAndCanOnlyBeUsedOnce(): void
    {
        $oauth = new OAuth($this->config, new FakeTransport([$this->tokenResponse()]));
        parse_str(parse_url($oauth->begin(), PHP_URL_QUERY), $query);
        try {
            $oauth->complete(['state' => 'wrong', 'code' => 'synthetic-code']);
            self::fail('Wrong state should fail.');
        } catch (AppError $error) {
            self::assertStringContainsString('state inválido', $error->getMessage());
        }
        $oauth->complete(['state' => $query['state'], 'code' => 'synthetic-code']);
        $this->expectExceptionMessage('state inválido');
        $oauth->complete(['state' => $query['state'], 'code' => 'synthetic-code']);
    }

    public function testRefreshWithoutReplacementPreservesRefreshTokenAndAuthorizationDate(): void
    {
        $refresh = new Response(200, '{"access_token":"synthetic-new-token","token_type":"Bearer","expires_in":3600}');
        $http = new FakeTransport([$this->tokenResponse(), $refresh]);
        $oauth = new OAuth($this->config, $http);
        $this->authorize($oauth);
        $store = new PrivateJsonStore($this->temporary . '/tokens', 'spotify');
        $before = $store->locked(function (PrivateJsonStore $store): array {
            $token = $store->read();
            $token['expires_at'] = time() - 1;
            $store->write($token);
            return $token;
        });
        self::assertSame('synthetic-new-token', $oauth->accessToken());
        $after = $store->locked(fn (PrivateJsonStore $store) => $store->read());
        self::assertSame($before['refresh_token'], $after['refresh_token']);
        self::assertSame($before['authorized_at'], $after['authorized_at']);
        self::assertSame($before['reauthorize_due_at'], $after['reauthorize_due_at']);
        self::assertSame('refresh_token', $http->requests[1]['options']['form_params']['grant_type']);
    }

    public function testRefreshRotationIsPersisted(): void
    {
        $oauth = new OAuth($this->config, new FakeTransport([$this->tokenResponse(), $this->tokenResponse(['access_token' => 'rotated-access', 'refresh_token' => 'rotated-refresh'])]));
        $this->authorize($oauth);
        self::assertSame('rotated-access', $oauth->accessToken('synthetic-token'));
        $stored = json_decode(file_get_contents($this->temporary . '/tokens/spotify.json'), true);
        self::assertSame('rotated-refresh', $stored['refresh_token']);
    }

    public function testRevokedRefreshTokenRequiresReauthorizationWithoutLeakingResponse(): void
    {
        $oauth = new OAuth($this->config, new FakeTransport([$this->tokenResponse(), new Response(400, '{"error":"invalid_grant","error_description":"synthetic-private-details"}')]));
        $this->authorize($oauth);
        try {
            $oauth->accessToken('synthetic-token');
            self::fail('Expected revoked authorization.');
        } catch (AuthorizationExpired $error) {
            self::assertStringNotContainsString('synthetic-private-details', $error->getMessage());
            self::assertFileDoesNotExist($this->temporary . '/tokens/spotify.json');
        }
    }

    public function testRejectedAccountNeverReplacesTheExistingAuthorization(): void
    {
        $http=new FakeTransport([$this->tokenResponse(),$this->tokenResponse(['access_token'=>'synthetic-other-account'])]);
        $oauth=new OAuth($this->config,$http,static function(string $token):void{
            if($token==='synthetic-other-account')throw new AppError('Different synthetic account.');
        });
        $this->authorize($oauth);$path=$this->temporary.'/tokens/spotify.json';$before=file_get_contents($path);
        parse_str(parse_url($oauth->begin(),PHP_URL_QUERY),$query);
        try{$oauth->complete(['state'=>$query['state'],'code'=>'other-synthetic-code']);self::fail('Account mismatch must fail.');}
        catch(AppError $error){self::assertSame('Different synthetic account.',$error->getMessage());}
        self::assertSame($before,file_get_contents($path));
        self::assertSame('synthetic-token',$oauth->accessToken());
    }

    public function testLocalhostRedirectIsRejected(): void
    {
        $config = new Config($this->temporary, ['SPOTIFY_CLIENT_ID' => 'test', 'SPOTIFY_CLIENT_SECRET' => 'test', 'SPOTIFY_REDIRECT_URI' => 'http://localhost:8888/callback']);
        $this->expectException(AppError::class);
        (new OAuth($config, new FakeTransport([])))->begin();
    }
}
