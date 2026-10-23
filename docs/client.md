# Cliente HTTP y Aprovisionamiento (`Client`)

La clase `Fpjd\Sso\Client` es el punto de enlace HTTP entre la aplicación satélite y el servidor central de Accounts.

---

## 🛠️ Métodos del Flujo OAuth2 / OIDC

### 1. `authorizeUrl(...)`
Genera la URL con parámetros query codificados bajo RFC 3986 para iniciar el login:
```php
$url = $accounts->authorizeUrl(
    state: $state,
    codeChallenge: $codeChallenge,
    selectAccount: false,
    loginHint: 'e4c9b362-9720-7f28-a28a-788bf30573be'
);
```

### 2. `exchangeCode(string $code, string $verifier): array`
Realiza una petición POST a `/api/token` con `grant_type=authorization_code`, enviando las credenciales de cliente (`client_id`, `client_secret`) y el `code_verifier`.
Devuelve un array con:
- `access_token`: JWT firmado en RS256 con tiempo de vida corto (15 min).
- `refresh_token`: Cadena aleatoria para renovación (expira en días).
- `user`: Datos básicos del usuario autenticado (`id`, `name`, `email`, etc.).

### 3. `refresh(string $refreshToken): array`
Realiza una petición POST a `/api/token` con `grant_type=refresh_token`. Rota el refresh token y entrega un nuevo par de tokens.

### 4. `revoke(string $refreshToken, bool $endSession = true): void`
Envía una petición POST a `/api/logout`. Si `$endSession` es `true`, también destruye la sesión en el navegador dentro del portal central de Accounts.

### 5. `sessionActive(string $refreshToken): ?bool`
Pregunta pasivamente a Accounts (sin rotar el refresh token) si la sesión sigue viva. Si Accounts está caído o hay timeout, devuelve `null` para permitir un comportamiento tolerante a fallos (*fail-open*).

### 6. `verify(string $jwt): object`
Valida criptográficamente el JWT:
1. Extrae el `kid` (Key ID) de la cabecera del JWT.
2. Consulta el conjunto de claves JWKS públicas (usando caché `jwks:fresh` o refresco forzado si el `kid` es desconocido).
3. Decodifica y valida firma con `Firebase\JWT\JWT::decode`.
4. Valida que el `iss` coincida con `ACCOUNTS_ISSUER` y el `aud` con `ACCOUNTS_CLIENT_ID`.
5. Retorna los claims en un objeto PHP (`$claims->sub`, `$claims->name`, etc.).
6. Rechaza los tokens de integración (con claim `act`): solo valen por `verifyDelegated()`.

---

## 🔗 Integraciones entre Apps (Token Exchange, RFC 8693)

Una app (cliente) llama a la API de otra (destino) **en nombre de su usuario**. En Accounts, la app
destino expone *scopes* y el administrador otorga a la app cliente los que necesita
(`/admin/apps/{destino}` → *Integraciones con otras apps*).

### `exchangeToken(string $subjectToken, string $audience, array $scopes): array`
Desde la API **cliente**: canjea el access token de sesión del usuario por uno para `$audience`.
- Devuelve `['access_token', 'expires_in', 'scope' => string[]]`; `scope` trae solo los otorgados.
- Se cachea en el `Store` hasta 30 s antes de vencer (máx. 5 min).
- `GrantException`: la sesión del usuario ya no es válida → 401, iniciar sesión de nuevo.
- `IntegrationException`: la integración no está habilitada (`invalid_scope` / `invalid_target`) → 403.
- `UnavailableException`: Accounts no responde → 503.

```php
$token = $client->exchangeToken($accessTokenDelUsuario, 'hoteles', ['redemptions.read']);
// GET https://api.hoteles…/api/integrations/… con  Authorization: Bearer {$token['access_token']}
```

### `verifyDelegated(string $jwt): object`
Desde la API **destino**: valida el Bearer recibido (firma, `iss`, `aud` = esta app, `exp`) y exige
que sea un token de integración. Claims: `sub` = la persona, `act->sub` / `client_id` = la app que
actúa en su nombre, `scope` = scopes separados por espacio. Comprobar el scope de cada ruta es
responsabilidad de la API destino.

---

## 👤 API de Aprovisionamiento de Usuarios (Basic Auth)

Accounts ofrece una API REST para que las aplicaciones satélites gestionen el alta de usuarios sin duplicar datos en tablas locales.

### `provisionUser(array $person, ?string $actedBy = null): array`
Crea o vincula a un usuario en Accounts.
```php
$result = $accounts->provisionUser([
    'identification' => '1020304050',
    'first_name' => 'Carlos',
    'last_name' => 'Pérez',
    'email' => 'carlos.perez@parquejaimeduque.com',
    'management_id' => 'uuid-gestion',
], actedBy: $currentAdminId);
```
Devuelve:
- `user`: Datos del usuario.
- `created`: `true` si fue creado desde cero, `false` si ya existía en Accounts y solo se le concedió acceso a esta aplicación.
- `temporary_password`: Contraseña temporal si fue creado por primera vez.
- `access_granted`: Confirmación de acceso habilitado.

### `grantAccess(string $accountId, ?string $actedBy = null)`
Otorga o rehabilita el acceso del usuario a esta aplicación satélite.

### `revokeAccess(string $accountId, ?string $actedBy = null)`
Revoca el acceso a esta aplicación (el usuario sigue existiendo y manteniendo acceso a sus otras apps del parque).

### `setManagement(string $accountId, ?string $managementId, ?string $actedBy = null)`
Actualiza el área de gestión asignada a la persona en Accounts.

### `userApps(string $accountId): array`
Devuelve la lista de aplicaciones del parque a las que este usuario tiene permiso, con sus URLs de acceso directo y enlaces de solicitud en caso de estar bloqueadas.
