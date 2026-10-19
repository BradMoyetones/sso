<?php

declare(strict_types=1);

namespace Fpjd\Sso\Test\Security;

use Fpjd\Sso\Security\TokenCipher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TokenCipherTest extends TestCase
{
    private string $key;
    private TokenCipher $cipher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = random_bytes(32);
        $this->cipher = new TokenCipher($this->key);
    }

    public function testRejectsInvalidKeyLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TokenCipher('too-short-key');
    }

    public function testEncryptAndDecryptSuccess(): void
    {
        $plaintext = 'Hello, SSO World! 12345';
        $encrypted = $this->cipher->encrypt($plaintext, 'test-purpose');

        $this->assertNotEmpty($encrypted);
        $this->assertNotEquals($plaintext, $encrypted);

        $decrypted = $this->cipher->decrypt($encrypted, 'test-purpose');
        $this->assertSame($plaintext, $decrypted);
    }

    public function testDecryptFailsOnPurposeMismatch(): void
    {
        $plaintext = 'Secret Token';
        $encrypted = $this->cipher->encrypt($plaintext, 'account');

        $decrypted = $this->cipher->decrypt($encrypted, 'different-purpose');
        $this->assertNull($decrypted, 'Decryption must fail when AAD purpose does not match.');
    }

    public function testDecryptFailsOnTamperedCiphertext(): void
    {
        $plaintext = 'Original Message';
        $encrypted = $this->cipher->encrypt($plaintext, 'account');

        // Tamper with bytes inside the base64url string
        $tampered = substr_replace($encrypted, 'XXXX', 10, 4);

        $decrypted = $this->cipher->decrypt($tampered, 'account');
        $this->assertNull($decrypted, 'Decryption must fail when ciphertext is altered.');
    }

    public function testDecryptFailsOnMalformedFormat(): void
    {
        $this->assertNull($this->cipher->decrypt('too-short', 'account'));
        $this->assertNull($this->cipher->decrypt('!@#$%^&*()', 'account'));
        $this->assertNull($this->cipher->decrypt('', 'account'));
    }
}
