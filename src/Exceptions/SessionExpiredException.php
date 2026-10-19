<?php

declare(strict_types=1);

namespace Fpjd\Sso\Exceptions;

/**
 * Se lanza cuando la sesión del usuario ha expirado o fue revocada en Accounts.
 */
class SessionExpiredException extends \RuntimeException implements SsoException
{
    public function __construct(
        string $message = 'La sesión ha expirado.',
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
