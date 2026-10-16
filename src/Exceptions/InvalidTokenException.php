<?php

declare(strict_types=1);

namespace Fpjd\Sso\Exceptions;

/**
 * Se lanza cuando los tokens o cookies de sesión presentan manipulación o firma inválida.
 */
class InvalidTokenException extends \RuntimeException
{
    public function __construct(
        string $message = 'El token o la sesión no son válidos.',
        int $code = 0,
        ?\Throwable $previous = null,
        private ?string $accountId = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getAccountId(): ?string
    {
        return $this->accountId;
    }
}
