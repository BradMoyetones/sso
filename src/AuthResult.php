<?php

declare(strict_types=1);

namespace Fpjd\Sso;

/**
 * Objeto de transferencia de datos con el resultado de una autenticación exitosa.
 */
class AuthResult
{
    /**
     * @param object $claims Claims decodificados del JWT verificado
     * @param string $accountId Identificador UUID de la cuenta en Accounts
     * @param array<string, mixed>|null $renewedTokens Tokens nuevos si ocurrió una rotación en segundo plano
     * @param array<string, mixed>|null $session Datos crudos de la sesión de la cookie
     */
    public function __construct(
        public readonly object $claims,
        public readonly string $accountId,
        public readonly ?array $renewedTokens = null,
        public readonly ?array $session = null,
    ) {
    }
}
