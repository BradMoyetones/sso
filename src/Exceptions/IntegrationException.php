<?php

declare(strict_types=1);

namespace Fpjd\Sso\Exceptions;

/**
 * Accounts rechazó un canje de token entre apps porque la integración no está habilitada:
 * la app destino no existe o está apagada (`invalid_target`) o no hay scopes otorgados para
 * lo pedido (`invalid_scope`). No es culpa del usuario: la app debe responder 403, no pedir login.
 */
class IntegrationException extends \RuntimeException implements SsoException
{
}
