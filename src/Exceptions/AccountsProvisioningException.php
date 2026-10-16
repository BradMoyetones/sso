<?php

declare(strict_types=1);

namespace Fpjd\Sso\Exceptions;

/**
 * Se lanza cuando la API de aprovisionamiento de Accounts rechaza la operación
 * por motivos de validación de negocio (documento duplicado, campos faltantes, 4xx).
 */
class AccountsProvisioningException extends \RuntimeException
{
    /**
     * @param int $httpStatus
     * @param string $error
     * @param string $errorDescription
     * @param array<string, mixed> $errors
     */
    public function __construct(
        private int $httpStatus,
        private string $error,
        string $errorDescription,
        private array $errors = []
    ) {
        parent::__construct($errorDescription, $httpStatus);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getError(): string
    {
        return $this->error;
    }

    /**
     * @return array<string, mixed>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
