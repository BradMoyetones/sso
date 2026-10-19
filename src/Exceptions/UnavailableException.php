<?php

declare(strict_types=1);

namespace Fpjd\Sso\Exceptions;

/**
 * Se lanza cuando el servidor de Accounts no responde, hay problemas de red,
 * o la configuración de credenciales del cliente es errónea.
 */
class UnavailableException extends \RuntimeException implements SsoException
{
}
