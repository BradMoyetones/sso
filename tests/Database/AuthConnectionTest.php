<?php

declare(strict_types=1);

namespace Fpjd\Sso\Test\Database;

use Fpjd\Sso\Database\AuthConnection;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

class AuthConnectionTest extends TestCase
{
    public function testRegistersAuthConnectionWithGivenConfig(): void
    {
        $capsule = new Capsule();

        AuthConnection::register($capsule, [
            'host' => 'db.internal',
            'database' => 'accounts_test',
            'username' => 'reader',
            'password' => 'secret',
        ]);

        $config = $capsule->getContainer()['config']['database.connections'][AuthConnection::NAME];

        $this->assertSame('auth', AuthConnection::NAME);
        $this->assertSame('mysql', $config['driver']);
        $this->assertSame('db.internal', $config['host']);
        $this->assertSame('accounts_test', $config['database']);
        $this->assertSame('reader', $config['username']);
        $this->assertSame('utf8mb4', $config['charset']);
    }
}
