<?php

declare(strict_types=1);

namespace Fpjd\Sso\Exceptions;

/**
 * Se lanza cuando un grant_type de OAuth2 falla o es rechazado (código vencido,
 * refresh token revocado, reutilizado o inválido). Requiere reiniciar login.
 */
class GrantException extends \RuntimeException implements SsoException
{
}
