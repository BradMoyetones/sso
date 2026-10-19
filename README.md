# FPJD SSO (`fpjd/sso`)

[![CI](https://github.com/BradMoyetones/sso/actions/workflows/ci.yml/badge.svg)](https://github.com/BradMoyetones/sso/actions/workflows/ci.yml)

SDK y cliente oficial de integración para el ecosistema de autenticación centralizada (SSO) de la **Fundación Parque Jaime Duque**.

Proporciona soporte completo para el flujo **OAuth2 / OIDC con PKCE**, verificación criptográfica de tokens JWT vía **JWKS**, gestión de sesiones multi-cuenta en **cookies HttpOnly seguras**, y acceso de solo lectura a los modelos de identidad de Accounts.

---

## 🗂️ Estructura del Paquete

El namespace aporta el contexto (`Fpjd\Sso`) y cada clase solo nombra su rol:

```text
src/
├── Client.php                     # OAuth2/OIDC + PKCE, JWKS y API de aprovisionamiento
├── SessionManager.php             # Sesiones multi-cuenta en cookies cifradas
├── Authenticator.php              # Orquestador para middlewares (verifica y renueva tokens)
├── AuthResult.php                 # Resultado de una autenticación exitosa
├── Contracts/Store.php            # Contrato de caché + locks
├── Storage/FileStore.php          # Implementación en disco de Store
├── Security/TokenCipher.php       # AES-256-GCM para cookies y relevos
├── Database/AuthConnection.php    # Registra la conexión de solo lectura 'auth'
├── Models/                        # Account, Company, Management (solo lectura)
└── Exceptions/                    # SsoException + excepciones semánticas
```

Si un nombre choca con una clase de tu app, usa un alias: `use Fpjd\Sso\Client as SsoClient;`.

> [!NOTE]
> ¿Vienes de la v1? Revisa la [guía de migración v1 → v2](./docs/upgrade-v2.md) y el [CHANGELOG](./CHANGELOG.md).

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
        "url": "https://github.com/BradMoyetones/sso.git"
    }
],
"require": {
    "fpjd/sso": "^2.0"
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
> El nombre de conexión `'auth'` está **estrictamente reservado** para los modelos de este paquete (`Account`, `Company`, `Management`).

En el punto de arranque de tu aplicación (donde inicializas Capsule):

```php
use Fpjd\Sso\Database\AuthConnection;

// Registra la conexión 'auth' automáticamente leyendo el .env
AuthConnection::register($capsule);
```

---

### 4. Uso en tus Controladores y Modelos

#### En tu modelo local `User`:
```php
use Fpjd\Sso\Models\Account;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class User extends Model
{
    public function authUser(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'user_auth_id');
    }
}
```

#### En tu `AuthController`:
```php
use Fpjd\Sso\Client;
use Fpjd\Sso\Exceptions\GrantException;
use Fpjd\Sso\SessionManager;

class AuthController
{
    public function __construct(
        private Client $accounts,
        private SessionManager $sessions,
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
- 🌐 [Cliente HTTP y Aprovisionamiento (`Client`)](./docs/client.md)
- 🍪 [Sesiones, Cookies y Autenticador (`SessionManager` y `Authenticator`)](./docs/session.md)
- 🔒 [Criptografía y Almacén de Caché (`TokenCipher` y `FileStore`)](./docs/security.md)
- 🗄️ [Modelos de Datos y Reserva de Conexión 'auth'](./docs/models/index.md)
- 🚀 [Guía de Integración Paso a Paso](./docs/integration-guide.md)
- ⬆️ [Migración v1 → v2](./docs/upgrade-v2.md)

---

## 📄 Licencia

Propiedad exclusiva de la Fundación Parque Jaime Duque. Prohibida su distribución o uso no autorizado fuera de las aplicaciones del parque.
