<?php

declare(strict_types=1);

namespace Fpjd\Sso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo de solo lectura para las empresas registradas en Accounts (FPJD, IDT, etc.).
 * Mapea a la vista pública `companies_public` en la base de datos de SSO.
 *
 * NOTA: La conexión 'auth' está estrictamente reservada para este paquete.
 */
class AuthCompany extends Model
{
    protected $connection = 'auth';
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
        return $this->hasMany(AuthManagement::class, 'company_id');
    }
}
