<?php

declare(strict_types=1);

namespace Fpjd\Sso;

/**
 * Contrato para el almacenamiento de caché y sincronización concurrente
 * (locks) requerido por el cliente SSO (JWKS, relevo de refresh tokens).
 */
interface StoreInterface
{
    /**
     * Obtiene el valor almacenado para la clave dada, o null si no existe o expiró.
     */
    public function get(string $key): ?string;

    /**
     * Almacena un valor con un tiempo de vida (TTL) en segundos.
     */
    public function put(string $key, string $value, int $ttl): void;

    /**
     * Adquiere un cerrojo exclusivo (mutex) para prevenir condiciones de carrera.
     * Retorna el identificador del cerrojo (resource/objeto) o null si no se pudo adquirir.
     *
     * @return mixed
     */
    public function lock(string $name, float $waitSeconds = 5.0);

    /**
     * Libera un cerrojo previamente adquirido.
     *
     * @param mixed $handle
     */
    public function unlock($handle): void;
}
