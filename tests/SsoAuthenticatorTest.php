<?php

declare(strict_types=1);

namespace Fpjd\Sso\Test;

use Fpjd\Sso\AccountSessions;
use Fpjd\Sso\AccountsClient;
use Fpjd\Sso\Exceptions\InvalidTokenException;
use Fpjd\Sso\Exceptions\UnauthenticatedException;
use Fpjd\Sso\FileStore;
use Fpjd\Sso\SsoAuthenticator;
use Fpjd\Sso\TokenCipher;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class SsoAuthenticatorTest extends TestCase
{
    private TokenCipher $cipher;
    private FileStore $store;
    private AccountSessions $sessions;
    private AccountsClient $client;
    private SsoAuthenticator $authenticator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cipher = new TokenCipher(random_bytes(32));
        $this->store = new FileStore(sys_get_temp_dir() . '/sso_auth_test_' . bin2hex(random_bytes(4)));
        $this->sessions = new AccountSessions($this->cipher, 'sso', false);
        $this->client = $this->createStub(AccountsClient::class);
        $this->authenticator = new SsoAuthenticator(
            $this->client,
            $this->sessions,
            $this->cipher,
            $this->store
        );
    }

    public function testAuthenticateThrowsWhenNoSessionInCookies(): void
    {
        $request = new ServerRequest('GET', '/api/profile');

        $this->expectException(UnauthenticatedException::class);
        $this->expectExceptionMessage('No hay una sesión activa.');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsWhenSubDoesNotMatchCookieAccount(): void
    {
        $userId = '018f8b80-60b6-7643-9812-34567890abcd';
        $payload = json_encode([
            'u' => $userId,
            'a' => 'jwt-token',
            'r' => 'refresh-token',
            'n' => 'John Doe',
            'e' => 'john@example.com',
            't' => time(),
        ]);

        $cookieName = 'sso_a_' . substr(hash('sha256', $userId), 0, 12);
        $cookieValue = $this->cipher->encrypt($payload, 'account');

        $request = (new ServerRequest('GET', '/api/profile'))
            ->withCookieParams([
                $cookieName => $cookieValue,
                'sso_last' => $userId,
            ]);

        // Mock verify returning claims with a DIFFERENT sub
        $claims = (object) [
            'sub' => 'different-user-id',
            'email' => 'hacker@example.com',
        ];

        $this->client->method('verify')->willReturn($claims);

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('La cuenta de la cookie no coincide con el token.');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateSuccess(): void
    {
        $userId = '018f8b80-60b6-7643-9812-34567890abcd';
        $payload = json_encode([
            'u' => $userId,
            'a' => 'valid-jwt-token',
            'r' => 'valid-refresh-token',
            'n' => 'John Doe',
            'e' => 'john@example.com',
            't' => time(),
        ]);

        $cookieName = 'sso_a_' . substr(hash('sha256', $userId), 0, 12);
        $cookieValue = $this->cipher->encrypt($payload, 'account');

        $request = (new ServerRequest('GET', '/api/profile'))
            ->withCookieParams([
                $cookieName => $cookieValue,
                'sso_last' => $userId,
            ]);

        $claims = (object) [
            'sub' => $userId,
            'email' => 'john@example.com',
            'name' => 'John Doe',
        ];

        $this->client->method('verify')->willReturn($claims);

        $result = $this->authenticator->authenticate($request);

        $this->assertSame($userId, $result->accountId);
        $this->assertSame($claims, $result->claims);
        $this->assertNull($result->renewedTokens);
    }
}
