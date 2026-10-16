<?php

declare(strict_types=1);

namespace Fpjd\Sso\Database;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Registrador de la conexión 'auth' en Eloquent Capsule.
 */
class AccountsConnection
{
    /** Nombre reservado para la conexión de base de datos de Accounts. */
    public const CONNECTION_NAME = 'auth';

    /**
     * Registra la conexión 'auth' en la instancia Capsule Manager dada.
     * Si no se proveen valores en $config, toma por defecto las variables de entorno.
     *
     * @param Capsule $capsule
     * @param array<string, mixed> $config Opciones de configuración manual (opcional)
     */
    public static function register(Capsule $capsule, array $config = []): void
    {
        $capsule->addConnection([
            'driver' => $config['driver'] ?? 'mysql',
            'host' => $config['host'] ?? ($_ENV['AUTH_DB_HOST'] ?? ($_ENV['DB_HOST'] ?? '127.0.0.1')),
            'port' => $config['port'] ?? ($_ENV['AUTH_DB_PORT'] ?? ($_ENV['DB_PORT'] ?? 3306)),
            'database' => $config['database'] ?? ($_ENV['AUTH_DB_NAME'] ?? 'accounts'),
            'username' => $config['username'] ?? ($_ENV['AUTH_DB_USER'] ?? 'root'),
            'password' => $config['password'] ?? ($_ENV['AUTH_DB_PASS'] ?? ''),
            'charset' => $config['charset'] ?? 'utf8mb4',
            'collation' => $config['collation'] ?? 'utf8mb4_unicode_ci',
            'prefix' => $config['prefix'] ?? '',
        ], self::CONNECTION_NAME);
    }
}
