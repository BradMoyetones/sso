# FPJD SSO (`fpjd/sso`)

SDK y cliente oficial de integración para el ecosistema de autenticación centralizada (SSO) de la **Fundación Parque Jaime Duque**.

Proporciona soporte completo para el flujo **OAuth2 / OIDC con PKCE**, verificación criptográfica de tokens JWT vía **JWKS**, gestión de sesiones multi-cuenta en **cookies HttpOnly seguras**, y acceso de solo lectura a los modelos de identidad de Accounts.

---

## 🚀 Requisitos

- **PHP 8.1** o superior.
- Extensiones PHP: `curl`, `json`, `openssl`.
- Gestor de dependencias: **Composer**.

---

## 🛠️ Modo Desarrollo (Para mantenedores de la librería)

Si estás trabajando directamente en este repositorio para extender o actualizar el SDK:

```bash
# 1. Instalar dependencias locales
composer install

# 2. Ejecutar la suite completa de pruebas unitarias
composer test

# O directamente con PHPUnit
vendor/bin/phpunit
```

---

## 📦 Instalación Básica en una Aplicación

### 1. Configurar el repositorio en el `composer.json` de tu proyecto

En el `composer.json` de tu aplicación (por ejemplo `cortesias-backend` o `almuerzos-backend`):

```json
"repositories": [
    {
        "type": "vcs",
        "url": "git@github.com:tu-usuario/accounts-sso-php.git"
    }
],
"require": {
    "fpjd/sso": "^1.0"
}
```

Luego ejecuta:
```bash
composer update fpjd/sso
```

---

### 2. Variables de Entorno Requeridas (`.env`)

```ini
# Criptografía local (64 caracteres hex: openssl rand -hex 32)
APP_KEY=tu_clave_secreta_hexadecimal_de_64_caracteres

# Configuración del servidor SSO Accounts
ACCOUNTS_URL=https://accounts.parquejaimeduque.com
ACCOUNTS_CLIENT_ID=cortesias
ACCOUNTS_CLIENT_SECRET=tu_secreto_de_cliente
ACCOUNTS_ISSUER=https://accounts.parquejaimeduque.com

# Base de datos de Accounts (Conexión 'auth' de solo lectura)
AUTH_DB_HOST=127.0.0.1
AUTH_DB_PORT=3306
AUTH_DB_NAME=parque_accounts
AUTH_DB_USER=usr_accounts_reader
AUTH_DB_PASS=tu_password_seguro

# Opciones opcionales de cookies
COOKIE_PREFIX=cortesias
APP_ENV=production
```

---

### 3. Registro de la Conexión de Base de Datos `'auth'`

> [!IMPORTANT]
> El nombre de conexión `'auth'` está **estrictamente reservado** para los modelos de este paquete (`AuthUser`, `AuthCompany`, `AuthManagement`).

En el punto de arranque de tu aplicación (donde inicializas Capsule):

```php
use Fpjd\Sso\Database\AccountsConnection;

// Registra la conexión 'auth' automáticamente leyendo el .env
AccountsConnection::register($capsule);
```

---

### 4. Uso en tus Controladores y Modelos

#### En tu modelo local `User`:
```php
use Fpjd\Sso\Models\AuthUser;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class User extends Model
{
    public function authUser(): BelongsTo
    {
        return $this->belongsTo(AuthUser::class, 'user_auth_id');
    }
}
```

#### En tu `AuthController`:
```php
use Fpjd\Sso\AccountsClient;
use Fpjd\Sso\AccountSessions;
use Fpjd\Sso\Exceptions\AccountsGrantException;

class AuthController
{
    public function __construct(
        private AccountsClient $accounts,
        private AccountSessions $sessions,
    ) {
    }

    public function login(Request $request, Response $response): Response
    {
        // ...
    }
}
```

---

## 📚 Documentación Detallada

Para consultar la arquitectura interna, ciclo de vida de los tokens, contratos y guías paso a paso, revisa la carpeta [`docs/`](./docs/):

- 🏛️ [Arquitectura Global y Flujo OIDC](./docs/index.md)
- 🌐 [Cliente HTTP y Aprovisionamiento (`AccountsClient`)](./docs/client.md)
- 🍪 [Sesiones, Cookies y Autenticador (`AccountSessions` y `SsoAuthenticator`)](./docs/session.md)
- 🔒 [Criptografía y Almacén de Caché (`TokenCipher` y `FileStore`)](./docs/security.md)
- 🗄️ [Modelos de Datos y Reserva de Conexión 'auth'](./docs/models/index.md)
- 🚀 [Guía de Integración Paso a Paso](./docs/integration-guide.md)

---

## 📄 Licencia

Propiedad exclusiva de la Fundación Parque Jaime Duque. Prohibida su distribución o uso no autorizado fuera de las aplicaciones del parque.
