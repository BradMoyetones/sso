<?php

declare(strict_types=1);

namespace Fpjd\Sso\Exceptions;

/**
 * Interfaz marcadora implementada por todas las excepciones del paquete.
 * Permite capturar cualquier error del SSO con un único `catch (SsoException $e)`.
 */
interface SsoException extends \Throwable
{
}
