# Modelos de Datos y Reserva de Conexión 'auth'

El paquete provee tres modelos Eloquent de **solo lectura** para acceder a la estructura pública de cuentas y organización del Parque Jaime Duque.

---

## 🔒 Regla Estricta: Reserva de la Conexión `'auth'`

> [!IMPORTANT]
> Los modelos `AuthUser`, `AuthCompany` y `AuthManagement` tienen definida internamente la propiedad:
> ```php
> protected $connection = 'auth';
> ```
> Por lo tanto, el nombre `'auth'` queda **estrictamente reservado en Capsule** para este propósito. Toda aplicación consumidora debe registrar su conexión secundaria bajo este alias.

---

## 🏛️ Modelos Disponibles

### 1. `Fpjd\Sso\Models\AuthUser`
Mapea a la vista MySQL de solo lectura `users_public`:
- **Propiedades**: `id` (UUID), `identification`, `first_name`, `last_name`, `email`, `phone`, `avatar_url`, `is_active`, `management_id`, `created_at`, `last_login_at`.
- **Atributos calculados**:
  - `full_name`: Nombre y apellido concatenados.
- **Relaciones**:
  - `management(): BelongsTo`: Relación con `AuthManagement`.
- **Helpers**:
  - `AuthUser::loadOrganization($authUsers)`: Carga ansiosa (*eager load*) de `management.company` de forma tolerante a fallos; si las vistas o permisos de empresa no están configurados en la base de datos de Accounts, no rompe la ejecución.

### 2. `Fpjd\Sso\Models\AuthCompany`
Mapea a la vista `companies_public`:
- **Propiedades**: `id` (UUID), `name`, `code`, `is_active`.
- **Relaciones**:
  - `managements(): HasMany`: Relación con las gestiones asociadas a esta empresa.

### 3. `Fpjd\Sso\Models\AuthManagement`
Mapea a la vista `managements_public`:
- **Propiedades**: `id` (UUID), `name`, `code`, `company_id`, `is_active`.
- **Relaciones**:
  - `company(): BelongsTo`: Relación con `AuthCompany`.

---

## 🔗 Relación Lógica Virtual con la Tabla Local `User`

En la base de datos de tu aplicación satélite (por ejemplo, Cortesías), la tabla `users` almacena las claves foráneas hacia los perfiles de tu app y la referencia hacia Accounts:

```php
use Fpjd\Sso\Models\AuthUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class User extends Model
{
    protected $connection = 'cortesias'; // Conexión local de la app
    protected $table = 'users';

    /**
     * Relación entre bases de datos diferentes.
     * Vincula users.user_auth_id con users_public.id en la conexión 'auth'.
     */
    public function authUser(): BelongsTo
    {
        return $this->belongsTo(AuthUser::class, 'user_auth_id');
    }
}
```

Gracias a Eloquent, puedes hacer consultas como:
```php
$user = User::with(['profile.permissions', 'authUser.management.company'])
    ->where('user_auth_id', $accountId)
    ->first();
```
Y acceder directamente a `$user->authUser->full_name` o `$user->authUser->email`.
