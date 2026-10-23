# Changelog

Todos los cambios relevantes de `fpjd/sso` se documentan en este archivo.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el proyecto usa [Versionado Semántico](https://semver.org/lang/es/).

## [2.2.0] - 2026-10-22

### Agregado
- Integraciones entre apps con token exchange (RFC 8693). Requiere las migraciones 024 y 025 de Accounts.
- `Client::exchangeToken()`: la API cliente canjea el token de sesión del usuario por uno para otra app. Se cachea hasta poco antes de vencer.
- `Client::verifyDelegated()`: la API destino valida el token de integración recibido (`act`, `scope`).
- `Exceptions\IntegrationException`: la integración no está habilitada en Accounts (`invalid_scope` / `invalid_target`).

### Cambiado
- `Client::verify()` rechaza los tokens de integración (claim `act`): un token delegado no abre sesión. Los tokens de sesión no cambian.

## [2.1.0] - 2026-10-21

### Agregado
- `Account::is_external` (booleano): cuenta externa de Accounts, por ejemplo un aliado en convenio. Requiere la migración 023 de Accounts, que lo expone en `users_public`.
- `Client::provisionUser()` documenta `is_external` (solo aplica al crear la persona).

## [2.0.0] - 2026-10-19

### Cambiado (incompatible)
- Clases renombradas para que el namespace aporte el contexto: `AccountsClient` → `Client`, `AccountSessions` → `SessionManager`, `SsoAuthenticator` → `Authenticator`, `SsoAuthResult` → `AuthResult`.
- Clases reubicadas en subnamespaces por responsabilidad: `Contracts\Store` (antes `StoreInterface`), `Storage\FileStore`, `Security\TokenCipher`.
- `Database\AccountsConnection` → `Database\AuthConnection` y `CONNECTION_NAME` → `NAME`.
- Modelos: `AuthUser` → `Account`, `AuthCompany` → `Company`, `AuthManagement` → `Management`.
- Excepciones sin prefijo: `GrantException`, `UnavailableException`, `ProvisioningException`.
- `Authenticator`: el parámetro `accounts` del constructor pasa a llamarse `client`.
- `firebase/php-jwt` sube de `^6.10` a `^7.1.1`: las versiones 6.x están afectadas por [GHSA-2x45-7fc3-mxwq](https://github.com/advisories/GHSA-2x45-7fc3-mxwq) y Composer 2.9 ya no las instala.

### Agregado
- Interfaz `Exceptions\SsoException`, implementada por todas las excepciones del paquete.
- Los modelos usan `AuthConnection::NAME` en lugar del literal `'auth'`.
- Pruebas para modelos, `AuthConnection`, excepciones, autoload PSR-4 y verificación RS256 + JWKS de extremo a extremo.
- CI en GitHub Actions (PHP 8.1 – 8.4, dependencias mínimas y máximas).
- Guía de migración en [`docs/upgrade-v2.md`](docs/upgrade-v2.md).

## [1.0.0] - 2026-10-16

### Agregado
- Versión inicial: cliente OAuth2/OIDC + PKCE, verificación JWKS, sesiones multi-cuenta en cookies cifradas, autenticador para middlewares, modelos de solo lectura de Accounts y API de aprovisionamiento.

[2.2.0]: https://github.com/BradMoyetones/sso/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/BradMoyetones/sso/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/BradMoyetones/sso/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/BradMoyetones/sso/releases/tag/v1.0.0
