# Guía de Migración v1 → v2

La versión **2.0.0** reorganiza el paquete para que el namespace aporte el contexto y el nombre de la clase solo describa su rol (la misma convención de `GuzzleHttp\Client` o Symfony). La lógica interna no cambia: solo cambian nombres, namespaces y algunos parámetros nombrados.

---

## 🧭 Criterio de Nombrado

1. **El namespace es el contexto.** `Fpjd\Sso\Client` ya dice que es el cliente del SSO; no hace falta `AccountsClient` ni `SsoClient`.
2. **Los subnamespaces agrupan por responsabilidad**: `Contracts`, `Storage`, `Security`, `Database`, `Models`, `Exceptions`.
3. **Los modelos usan el vocabulario de Accounts**: la identidad se llama *account*, por eso `Account` (y no `User`, que chocaría con el modelo local de cada app).
4. **Si hay choque de nombres en tu app, usa alias** de PHP:
   ```php
   use Fpjd\Sso\Client as SsoClient;
   use Fpjd\Sso\Models\Company as SsoCompany;
   ```

---

## 🗺️ Mapa de Clases

| v1.x | v2.0 |
|---|---|
| `Fpjd\Sso\AccountsClient` | `Fpjd\Sso\Client` |
| `Fpjd\Sso\AccountSessions` | `Fpjd\Sso\SessionManager` |
| `Fpjd\Sso\SsoAuthenticator` | `Fpjd\Sso\Authenticator` |
| `Fpjd\Sso\SsoAuthResult` | `Fpjd\Sso\AuthResult` |
| `Fpjd\Sso\StoreInterface` | `Fpjd\Sso\Contracts\Store` |
| `Fpjd\Sso\FileStore` | `Fpjd\Sso\Storage\FileStore` |
| `Fpjd\Sso\TokenCipher` | `Fpjd\Sso\Security\TokenCipher` |
| `Fpjd\Sso\Database\AccountsConnection` | `Fpjd\Sso\Database\AuthConnection` |
| `AccountsConnection::CONNECTION_NAME` | `AuthConnection::NAME` (sigue valiendo `'auth'`) |
| `Fpjd\Sso\Models\AuthUser` | `Fpjd\Sso\Models\Account` |
| `Fpjd\Sso\Models\AuthCompany` | `Fpjd\Sso\Models\Company` |
| `Fpjd\Sso\Models\AuthManagement` | `Fpjd\Sso\Models\Management` |
| `Exceptions\AccountsGrantException` | `Exceptions\GrantException` |
| `Exceptions\AccountsUnavailableException` | `Exceptions\UnavailableException` |
| `Exceptions\AccountsProvisioningException` | `Exceptions\ProvisioningException` |
| `Exceptions\InvalidTokenException` | Sin cambios |
| `Exceptions\SessionExpiredException` | Sin cambios |
| `Exceptions\UnauthenticatedException` | Sin cambios |
| — | **Nuevo:** `Exceptions\SsoException` (interfaz común) |

---

## ⚠️ Cambios que Pueden Romper Código

### Parámetro nombrado del `Authenticator`
Si construyes el autenticador con argumentos nombrados, el primero pasa de `accounts:` a `client:`:

```php
// v1
new SsoAuthenticator(accounts: $client, sessions: $sessions, cipher: $cipher);

// v2
new Authenticator(client: $client, sessions: $sessions, cipher: $cipher);
```

Con autowiring (PHP-DI) no hay que hacer nada: basta con actualizar los type-hints.

### Parámetro de `Account::loadOrganization()`
El parámetro pasó de `$authUsers` a `$accounts`. Solo afecta si lo llamabas con argumento nombrado.

---

## ✨ Novedad: `SsoException`

Todas las excepciones del paquete implementan `Fpjd\Sso\Exceptions\SsoException`, así que puedes capturar cualquier error del SSO en un solo bloque:

```php
use Fpjd\Sso\Exceptions\SsoException;

try {
    $client->provisionUser($person);
} catch (SsoException $e) {
    // Cualquier error del paquete
}
```

Todas siguen extendiendo `\RuntimeException`, por lo que los `catch` existentes siguen funcionando.

---

## ✅ Pasos para Actualizar una App

1. En `composer.json`: `"fpjd/sso": "^2.0"` y luego `composer update fpjd/sso`.
2. Reemplaza los `use` según la tabla de arriba.
3. Actualiza type-hints y llamadas estáticas (`AccountsClient::safeReturnPath()` → `Client::safeReturnPath()`, etc.).
4. Corre tus pruebas.

> [!TIP]
> El nombre de las relaciones de tus modelos locales (por ejemplo `User::authUser()`) **no** necesita cambiar: solo cambia la clase a la que apuntan (`Account::class`). Mantenerlo evita tocar el JSON que consume tu frontend.
