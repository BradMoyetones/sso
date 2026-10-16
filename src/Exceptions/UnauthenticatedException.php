<?php

declare(strict_types=1);

namespace Fpjd\Sso\Exceptions;

/**
 * Se lanza cuando no existe ninguna sesión activa en la petición HTTP.
 */
class UnauthenticatedException extends \RuntimeException
{
}
