<?php

declare(strict_types=1);

namespace Fpjd\Sso\Test;

use Fpjd\Sso\SessionManager;
use Fpjd\Sso\Security\TokenCipher;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class SessionManagerTest extends TestCase
{
    private TokenCipher $cipher;
    private SessionManager $sessions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cipher = new TokenCipher(random_bytes(32));
        $this->sessions = new SessionManager(
            cipher: $this->cipher,
            cookiePrefix: 'testsso',
            secure: false
        );
    }

    public function testIssueAndReadFlowCookie(): void
    {
        $state = 'state-abc-123';
        $flowData = [
            'v' => 'code-verifier-456',
            'r' => '/dashboard',
        ];

        $response = new Response();
        $response = $this->sessions->issueFlow($response, $state, $flowData);

        $setCookies = $response->getHeader('Set-Cookie');
        $this->assertNotEmpty($setCookies);

        // Parse cookie value from Set-Cookie header
        $cookieHeader = $setCookies[0];
        preg_match('/^([^=]+)=([^;]+)/', $cookieHeader, $matches);
        $cookieName = $matches[1];
        $cookieValue = $matches[2];

        // Simulate incoming request with that cookie
        $request = (new ServerRequest('GET', '/callback'))
            ->withCookieParams([$cookieName => $cookieValue]);

        $read = $this->sessions->readFlow($request, $state);
        $this->assertNotNull($read);
        $this->assertSame('code-verifier-456', $read['v']);
        $this->assertSame('/dashboard', $read['r']);
    }

    public function testForgetFlowCookie(): void
    {
        $state = 'state-to-forget';
        $response = new Response();
        $response = $this->sessions->forgetFlow($response, $state);

        $setCookies = $response->getHeader('Set-Cookie');
        $this->assertNotEmpty($setCookies);
        $this->assertStringContainsString('Max-Age=0', $setCookies[0]);
    }

    public function testStoreAndFindAccountSession(): void
    {
        $request = new ServerRequest('GET', '/');
        $response = new Response();

        $userId = '018f8b80-60b6-7643-9812-34567890abcd';
        $tokens = [
            'access_token' => 'mock-access-token',
            'refresh_token' => 'mock-refresh-token',
            'refresh_expires_in' => 86400,
            'user' => [
                'id' => $userId,
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
            ],
        ];

        $response = $this->sessions->store($request, $response, $tokens, makeLast: true);
        $setCookies = $response->getHeader('Set-Cookie');
        $this->assertCount(2, $setCookies); // Session cookie + last cookie

        // Extract cookies
        $cookies = [];
        foreach ($setCookies as $header) {
            if (preg_match('/^([^=]+)=([^;]+)/', $header, $m)) {
                $cookies[$m[1]] = $m[2];
            }
        }

        $authenticatedRequest = (new ServerRequest('GET', '/api/resource'))
            ->withCookieParams($cookies);

        $found = $this->sessions->find($authenticatedRequest, $userId);
        $this->assertNotNull($found);
        $this->assertSame($userId, $found['u']);
        $this->assertSame('mock-access-token', $found['a']);
        $this->assertSame('mock-refresh-token', $found['r']);
        $this->assertSame('Jane Doe', $found['n']);

        // Test last used
        $this->assertSame($userId, $this->sessions->lastUsed($authenticatedRequest));
    }

    public function testRequestedAccountHeaderExtraction(): void
    {
        $validUuid = '018f8b80-60b6-7643-9812-34567890abcd';
        $request = (new ServerRequest('GET', '/api'))
            ->withHeader('X-Account', $validUuid);

        $this->assertSame(strtolower($validUuid), $this->sessions->requested($request));

        $invalidRequest = (new ServerRequest('GET', '/api'))
            ->withHeader('X-Account', 'invalid-uuid-format');

        $this->assertNull($this->sessions->requested($invalidRequest));
    }

    public function testMaxAccountsEviction(): void
    {
        $cookies = [];
        $response = new Response();

        // Add 3 accounts (max limit)
        for ($i = 1; $i <= 3; $i++) {
            $id = sprintf('018f8b80-60b6-7643-9812-34567890000%d', $i);
            $req = (new ServerRequest('GET', '/'))->withCookieParams($cookies);
            $res = $this->sessions->store($req, new Response(), [
                'access_token' => "at-$i",
                'refresh_token' => "rt-$i",
                'user' => ['id' => $id, 'name' => "User $i"],
            ]);

            foreach ($res->getHeader('Set-Cookie') as $hdr) {
                if (preg_match('/^([^=]+)=([^;]+)/', $hdr, $m)) {
                    $cookies[$m[1]] = $m[2];
                }
            }
        }

        $reqAfter3 = (new ServerRequest('GET', '/'))->withCookieParams($cookies);
        $this->assertCount(3, $this->sessions->all($reqAfter3));

        // Sleep 1 second so timestamps differ slightly
        sleep(1);

        // Add a 4th account, which should trigger eviction of the oldest
        $id4 = '018f8b80-60b6-7643-9812-345678900004';
        $res4 = $this->sessions->store($reqAfter3, new Response(), [
            'access_token' => 'at-4',
            'refresh_token' => 'rt-4',
            'user' => ['id' => $id4, 'name' => 'User 4'],
        ]);

        // Check that an eviction cookie (Max-Age=0) was issued
        $setCookies = $res4->getHeader('Set-Cookie');
        $hasEviction = false;
        foreach ($setCookies as $hdr) {
            if (str_contains($hdr, 'Max-Age=0')) {
                $hasEviction = true;
                break;
            }
        }
        $this->assertTrue($hasEviction, 'Oldest account should have been evicted when exceeding MAX_ACCOUNTS.');
    }
}
