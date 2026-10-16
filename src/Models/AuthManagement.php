<?php

declare(strict_types=1);

namespace Fpjd\Sso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo de solo lectura para las gestiones (áreas operativas) del SSO.
 * Mapea a la vista pública `managements_public` en la base de datos de SSO.
 *
 * NOTA: La conexión 'auth' está estrictamente reservada para este paquete.
 */
class AuthManagement extends Model
{
    protected $connection = 'auth';
    protected $table = 'managements_public';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Empresa a la cual pertenece esta gestión.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(AuthCompany::class, 'company_id');
    }
}
