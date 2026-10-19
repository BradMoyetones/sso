<?php

declare(strict_types=1);

namespace Fpjd\Sso\Test;

use Fpjd\Sso\Client;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new Client(
            baseUrl: 'https://auth.example.com',
            clientId: 'test-app',
            clientSecret: 'secret-xyz'
        );
    }

    public function testGenerateCodeVerifier(): void
    {
        $verifier = Client::generateCodeVerifier();

        $this->assertNotEmpty($verifier);
        // Base64Url string should only contain alphanumeric, '-', '_'
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $verifier);
        // Length of 48 random bytes encoded in base64url is 64 chars
        $this->assertSame(64, strlen($verifier));
    }

    public function testGenerateCodeChallenge(): void
    {
        $verifier = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
        // Known S256 challenge for above verifier
        $expectedChallenge = Client::base64Url(hash('sha256', $verifier, true));

        $challenge = Client::generateCodeChallenge($verifier);
        $this->assertSame($expectedChallenge, $challenge);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $challenge);
    }

    public function testBase64Url(): void
    {
        $data = "\x00\x01\x02\xfd\xfe\xff";
        $encoded = Client::base64Url($data);

        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
    }

    public function testSafeReturnPath(): void
    {
        $this->assertSame('/dashboard', Client::safeReturnPath('/dashboard'));
        $this->assertSame('/requests/123?page=2', Client::safeReturnPath('/requests/123?page=2'));

        // Reject external/malicious paths
        $this->assertSame('/', Client::safeReturnPath('//evil.com'));
        $this->assertSame('/', Client::safeReturnPath('https://evil.com'));
        $this->assertSame('/', Client::safeReturnPath('javascript:alert(1)'));
        $this->assertSame('/', Client::safeReturnPath(''));
        $this->assertSame('/', Client::safeReturnPath(null));
        $this->assertSame('/fallback', Client::safeReturnPath(null, '/fallback'));
    }

    public function testAuthorizeUrlGeneration(): void
    {
        $state = 'state-123';
        $challenge = 'challenge-456';

        $url = $this->client->authorizeUrl($state, $challenge);

        $parsed = parse_url($url);
        $this->assertSame('https', $parsed['scheme']);
        $this->assertSame('auth.example.com', $parsed['host']);
        $this->assertSame('/authorize', $parsed['path']);

        parse_str($parsed['query'], $query);
        $this->assertSame('test-app', $query['client_id']);
        $this->assertSame('state-123', $query['state']);
        $this->assertSame('challenge-456', $query['code_challenge']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertArrayNotHasKey('prompt', $query);
        $this->assertArrayNotHasKey('login_hint', $query);
    }

    public function testAuthorizeUrlWithPromptAndHint(): void
    {
        $url = $this->client->authorizeUrl(
            state: 'state-abc',
            codeChallenge: 'challenge-xyz',
            selectAccount: true,
            loginHint: '018f8b80-60b6-7643-9812-34567890abcd'
        );

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertSame('018f8b80-60b6-7643-9812-34567890abcd', $query['login_hint']);
    }
}
