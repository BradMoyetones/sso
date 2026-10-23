<?php

declare(strict_types=1);

namespace Fpjd\Sso\Test;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Fpjd\Sso\Client;
use Fpjd\Sso\Storage\FileStore;
use PHPUnit\Framework\TestCase;

/**
 * Verifies RS256 tokens end to end against a JWKS served from the cache, without network access.
 */
class ClientVerifyTest extends TestCase
{
    private const KID = 'test-key-1';

    private static string $privateKey;
    private static string $jwks;

    private string $cacheDir;
    private Client $client;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $rsa = openssl_pkey_get_details($key)['rsa'];

        self::$privateKey = $pem;
        self::$jwks = json_encode(['keys' => [[
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => self::KID,
            'n' => Client::base64Url($rsa['n']),
            'e' => Client::base64Url($rsa['e']),
        ]]]);
    }

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/fpjd_sso_verify_' . bin2hex(random_bytes(6));
        $store = new FileStore($this->cacheDir);
        $store->put('jwks:fresh', self::$jwks, 300);

        $this->client = new Client(
            store: $store,
            baseUrl: 'https://auth.invalid',
            clientId: 'cortesias',
            clientSecret: 'secret',
            issuer: 'https://auth.example.com'
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cacheDir . '/*') ?: []);
        @rmdir($this->cacheDir);
    }

    public function testVerifiesValidToken(): void
    {
        $claims = $this->client->verify($this->token());

        $this->assertSame('018f8b80-60b6-7643-9812-34567890abcd', $claims->sub);
        $this->assertSame('cortesias', $claims->aud);
    }

    public function testRejectsExpiredToken(): void
    {
        $this->expectException(ExpiredException::class);

        $this->client->verify($this->token(['exp' => time() - 3600]));
    }

    public function testRejectsWrongAudience(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('aud');

        $this->client->verify($this->token(['aud' => 'otra-app']));
    }

    public function testRejectsWrongIssuer(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('iss');

        $this->client->verify($this->token(['iss' => 'https://evil.example.com']));
    }

    public function testRejectsTokenSignedWithAnotherKey(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $pem);

        $this->expectException(\Throwable::class);

        $this->client->verify(JWT::encode($this->claims(), $pem, 'RS256', self::KID));
    }

    public function testSessionVerifyRejectsIntegrationTokens(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->client->verify($this->token(['act' => ['sub' => 'visitantes'], 'scope' => 'redemptions.read']));
    }

    public function testVerifiesIntegrationToken(): void
    {
        $claims = $this->client->verifyDelegated($this->token(['act' => ['sub' => 'visitantes'], 'scope' => 'redemptions.read']));

        $this->assertSame('visitantes', $claims->act->sub);
        $this->assertSame('redemptions.read', $claims->scope);
    }

    public function testVerifyDelegatedRejectsSessionTokens(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->client->verifyDelegated($this->token());
    }

    public function testExchangeTokenIsServedFromCacheWithoutCallingAccounts(): void
    {
        // baseUrl es https://auth.invalid: si fuera a la red, fallaría con UnavailableException.
        $store = new FileStore($this->cacheDir);
        $subject = $this->token();
        $cached = ['access_token' => 'delegated-jwt', 'expires_in' => 300, 'scope' => ['redemptions.read', 'redemptions.redeem']];
        $store->put('xchg:' . hash('sha256', "$subject|hoteles|redemptions.read redemptions.redeem"), (string) json_encode($cached), 60);

        // El orden de los scopes pedidos no cambia la llave de la caché.
        $this->assertSame($cached, $this->client->exchangeToken($subject, 'hoteles', ['redemptions.redeem', 'redemptions.read']));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function token(array $overrides = []): string
    {
        return JWT::encode($this->claims($overrides), self::$privateKey, 'RS256', self::KID);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        return $overrides + [
            'iss' => 'https://auth.example.com',
            'aud' => 'cortesias',
            'sub' => '018f8b80-60b6-7643-9812-34567890abcd',
            'iat' => time(),
            'exp' => time() + 300,
        ];
    }
}
