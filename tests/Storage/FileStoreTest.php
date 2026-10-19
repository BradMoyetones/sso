<?php

declare(strict_types=1);

namespace Fpjd\Sso\Test\Storage;

use Fpjd\Sso\Storage\FileStore;
use PHPUnit\Framework\TestCase;

class FileStoreTest extends TestCase
{
    private string $tempDir;
    private FileStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/fpjd_sso_test_' . bin2hex(random_bytes(6));
        $this->store = new FileStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Cleanup temp directory
        $files = glob($this->tempDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testGetNonExistentKeyReturnsNull(): void
    {
        $this->assertNull($this->store->get('non-existent-key'));
    }

    public function testPutAndGetSuccess(): void
    {
        $this->store->put('user:123', 'John Doe Data', 60);
        $this->assertSame('John Doe Data', $this->store->get('user:123'));
    }

    public function testExpiredKeyReturnsNull(): void
    {
        // Negative TTL so it's already expired
        $this->store->put('expired:key', 'Ancient Data', -10);
        $this->assertNull($this->store->get('expired:key'));
    }

    public function testLockAndUnlockSuccess(): void
    {
        $handle1 = $this->store->lock('resource:test', 0.5);
        $this->assertNotNull($handle1, 'Should acquire initial lock.');

        // Attempting to lock the same resource with non-blocking/short timeout from another process/call
        // In the same process flock on another handle to the same file:
        $handle2 = $this->store->lock('resource:test', 0.1);
        $this->assertNull($handle2, 'Should fail to acquire lock while held.');

        $this->store->unlock($handle1);

        // After unlock, locking should succeed
        $handle3 = $this->store->lock('resource:test', 0.5);
        $this->assertNotNull($handle3, 'Should reacquire lock after unlock.');
        $this->store->unlock($handle3);
    }
}
