# Sesiones, Cookies y Autenticador

La gestión de sesiones del paquete `fpjd/sso` está diseñada bajo el estándar de **cookies HttpOnly protegidas criptográficamente**, eliminando la necesidad de guardar tokens en `localStorage` o `sessionStorage` en el frontend, lo que mitiga vulnerabilidades XSS.

---

## 🍪 Formato y Nombres de Cookies (`SessionManager`)

El nombre base de las cookies se deriva de `$cookiePrefix` (o `COOKIE_PREFIX` / `APP_NAME` en `.env`):

| Nombre de la Cookie | Propósito | Tiempo de Vida (TTL) |
| :--- | :--- | :--- |
| `<prefix>_a_<hash12(uid)>` | Almacena los tokens cifrados (`access_token`, `refresh_token`, `user_id`) de una cuenta específica. | Equivalente a la caducidad del refresh token (~24h - 30d). |
| `<prefix>_last` | Guarda el UUID de la última cuenta que interactuó con la aplicación. | 30 días. |
| `<prefix>_f_<hash16(state)>` | Cookie de flujo temporal que guarda el `code_verifier` y la ruta de retorno para el canje PKCE. | 10 minutos (600 s). |

### Banderas de Seguridad de las Cookies
Todas las cookies emitidas por `SessionManager` incluyen:
- `HttpOnly`: Inaccesibles desde JavaScript.
- `SameSite=Lax`: Protege contra ataques CSRF mientras permite redirecciones de navegación legítimas.
- `Path=/`: Válidas para todas las rutas del dominio.
- `Secure` + Prefijo `__Host-`: Activado automáticamente cuando `APP_ENV=production` o el flag `$secure` es `true`.

---

## 📑 Autenticación en Middlewares (`Authenticator`)

`Fpjd\Sso\Authenticator` desacopla completamente el framework HTTP de la lógica de tokens.

### Método `authenticate(Request $request): AuthResult`
1. Revisa si la petición incluye la cabecera `X-Account`. Si viene, busca la cookie correspondiente a esa cuenta; de lo contrario, toma la última usada (`<prefix>_last`) o la única sesión activa.
2. Si no hay sesión, lanza `UnauthenticatedException`.
3. Intenta verificar el JWT con `Client::verify()`.
4. Si el JWT venció (`ExpiredException`), ejecuta la **renovación atómica**:
   - Adquiere un cerrojo exclusivo (`lock:refresh:<uid>`) para evitar que múltiples llamadas simultáneas de la misma pestaña rotan el refresh token al mismo tiempo.
   - Si otra petición ya lo rotó hace instantes, reutiliza el token nuevo dejado en la caché de relevo (`handoff:<hash(refresh)>`).
   - Si no, contacta a Accounts para obtener un par de tokens frescos y guarda el relevo por 30 segundos.
5. Retorna un objeto `AuthResult`:
   ```php
   $result->claims;        // Objeto decodificado (sub, aud, iss, etc.)
   $result->accountId;     // UUID del usuario autenticado
   $result->renewedTokens; // Array con tokens nuevos si hubo rotación (o null)
   ```

### Método `withRenewal(...)`
Si `$result->renewedTokens` no es null, adjunta la nueva cookie HttpOnly a la respuesta HTTP:
```php
return $authenticator->withRenewal($request, $response, $result->renewedTokens);
```

### Método `forget(...)`
Destruye la cookie de la cuenta y, si era la última activa, limpia también `<prefix>_last`:
```php
return $authenticator->forget($request, $response, $accountId);
```
