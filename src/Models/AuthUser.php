<?php

declare(strict_types=1);

namespace Fpjd\Sso\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de solo lectura para la identidad pública de usuarios en Accounts (SSO).
 * Mapea a la vista `users_public` (excluyendo contraseñas y datos sensibles).
 *
 * NOTA: La conexión 'auth' está estrictamente reservada para este paquete.
 */
class AuthUser extends Model
{
    protected $connection = 'auth';
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
        return $this->belongsTo(AuthManagement::class, 'management_id');
    }

    /**
     * Carga de forma tolerante `management.company` sobre uno o varios usuarios sin romper
     * si la organización no está disponible o no se tienen permisos en la base de datos.
     *
     * @param AuthUser|iterable<AuthUser|null>|null $authUsers
     */
    public static function loadOrganization(self|iterable|null $authUsers): void
    {
        $list = $authUsers instanceof self
            ? [$authUsers]
            : array_filter(is_iterable($authUsers) ? iterator_to_array($authUsers, false) : []);

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
