<?php

declare(strict_types=1);

namespace Fpjd\Sso\Models;

use Fpjd\Sso\Database\AuthConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo de solo lectura para las empresas registradas en Accounts (FPJD, IDT, etc.).
 * Mapea a la vista pública `companies_public` en la base de datos de SSO.
 *
 * NOTA: La conexión AuthConnection::NAME ('auth') está estrictamente reservada para este paquete.
 */
class Company extends Model
{
    protected $connection = AuthConnection::NAME;
    protected $table = 'companies_public';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Gestiones (áreas) dependientes de esta empresa.
     */
    public function managements(): HasMany
    {
        return $this->hasMany(Management::class, 'company_id');
    }
}
