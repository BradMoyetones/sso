<?php

declare(strict_types=1);

namespace Fpjd\Sso\Test\Exceptions;

use Fpjd\Sso\Exceptions\GrantException;
use Fpjd\Sso\Exceptions\InvalidTokenException;
use Fpjd\Sso\Exceptions\ProvisioningException;
use Fpjd\Sso\Exceptions\SessionExpiredException;
use Fpjd\Sso\Exceptions\SsoException;
use Fpjd\Sso\Exceptions\UnauthenticatedException;
use Fpjd\Sso\Exceptions\UnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SsoExceptionTest extends TestCase
{
    /**
     * @return array<string, array{\Throwable}>
     */
    public static function exceptions(): array
    {
        return [
            'grant' => [new GrantException('invalid_grant')],
            'unavailable' => [new UnavailableException('down')],
            'provisioning' => [new ProvisioningException(422, 'validation', 'Datos inválidos.')],
            'invalid token' => [new InvalidTokenException()],
            'session expired' => [new SessionExpiredException()],
            'unauthenticated' => [new UnauthenticatedException()],
        ];
    }

    #[DataProvider('exceptions')]
    public function testEveryExceptionImplementsSsoException(\Throwable $exception): void
    {
        $this->assertInstanceOf(SsoException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testSsoExceptionCanBeCaughtGenerically(): void
    {
        try {
            throw new GrantException('invalid_grant');
        } catch (SsoException $e) {
            $this->assertSame('invalid_grant', $e->getMessage());
        }
    }

    public function testProvisioningExceptionExposesApiError(): void
    {
        $e = new ProvisioningException(409, 'duplicate', 'Ya existe.', ['identification' => ['taken']]);

        $this->assertSame(409, $e->getHttpStatus());
        $this->assertSame(409, $e->getCode());
        $this->assertSame('duplicate', $e->getError());
        $this->assertSame(['identification' => ['taken']], $e->getErrors());
    }

    public function testSessionExceptionsCarryAccountId(): void
    {
        $id = '018f8b80-60b6-7643-9812-34567890abcd';

        $this->assertSame($id, (new SessionExpiredException('x', 0, null, $id))->getAccountId());
        $this->assertSame($id, (new InvalidTokenException('x', 0, null, $id))->getAccountId());
    }
}
