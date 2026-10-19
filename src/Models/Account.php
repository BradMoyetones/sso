<?php

declare(strict_types=1);

namespace Fpjd\Sso\Models;

use Fpjd\Sso\Database\AuthConnection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de solo lectura para la identidad pública de usuarios en Accounts (SSO).
 * Mapea a la vista `users_public` (excluyendo contraseñas y datos sensibles).
 *
 * NOTA: La conexión AuthConnection::NAME ('auth') está estrictamente reservada para este paquete.
 */
class Account extends Model
{
    protected $connection = AuthConnection::NAME;
    protected $table = 'users_public';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $appends = ['full_name'];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    /**
     * Nombre completo calculado (first_name + last_name).
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}"));
    }

    /**
     * Gestión institucional del usuario (NULL si no tiene asignada).
     */
    public function management(): BelongsTo
    {
        return $this->belongsTo(Management::class, 'management_id');
    }

    /**
     * Carga de forma tolerante `management.company` sobre uno o varios usuarios sin romper
     * si la organización no está disponible o no se tienen permisos en la base de datos.
     *
     * @param Account|iterable<Account|null>|null $accounts
     */
    public static function loadOrganization(self|iterable|null $accounts): void
    {
        $list = $accounts instanceof self
            ? [$accounts]
            : array_filter(match (true) {
                is_array($accounts) => array_values($accounts),
                $accounts instanceof \Traversable => iterator_to_array($accounts, false),
                default => [],
            });

        if (!$list) {
            return;
        }

        try {
            (new \Illuminate\Database\Eloquent\Collection($list))->load('management.company');
        } catch (\Throwable $e) {
            error_log('[fpjd/sso] Organización no disponible en Accounts: ' . $e->getMessage());
        }
    }
}
