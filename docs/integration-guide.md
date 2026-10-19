# Guía de Integración de `fpjd/sso`

Esta guía describe paso a paso cómo integrar la biblioteca `fpjd/sso` en una aplicación PHP (como Slim 4, Laravel o cualquier framework compatible con PSR-7 / PSR-15).

---

## 1. Instalación vía Composer

Mientras el paquete se encuentre en un repositorio en GitHub, puedes instalarlo agregando la directiva `vcs` a tu `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/BradMoyetones/sso.git"
    }
  ],
  "require": {
    "fpjd/sso": "^2.0"
  }
}
```

Luego ejecuta:
```bash
composer update fpjd/sso
```

---

## 2. Variables de Entorno (`.env`)

Configura las siguientes variables en el entorno de tu aplicación consumidora:

```env
# URL base del servidor SSO y credenciales de cliente OAuth2
SSO_BASE_URL="https://auth.parquejaimeduke.com"
SSO_CLIENT_ID="cortesias"
SSO_CLIENT_SECRET="tu_client_secret_super_seguro"
SSO_REDIRECT_URI="https://api.cortesias.parquejaimeduke.com/auth/callback"
SSO_FRONTEND_URL="https://cortesias.parquejaimeduke.com"

# Clave simétrica para cifrado de refresh tokens y cookies de sesión (32 bytes en base64)
# Generar con: php -r 'echo base64_encode(random_bytes(32));'
SSO_TOKEN_CIPHER_KEY="base64:claveDe32BytesEnBase64..."

# Credenciales para conexión de solo lectura a la base de datos de Auth (SSO)
DB_AUTH_HOST="127.0.0.1"
DB_AUTH_PORT="3306"
DB_AUTH_DATABASE="jaimeduke_auth"
DB_AUTH_USERNAME="auth_readonly"
DB_AUTH_PASSWORD="secret_password"
```

---

## 3. Registro en el Contenedor de Dependencias (DI)

Ejemplo con PHP-DI (`dependencies.php` o `container.php`):

```php
use Fpjd\Sso\Authenticator;
use Fpjd\Sso\Client;
use Fpjd\Sso\Contracts\Store;
use Fpjd\Sso\Security\TokenCipher;
use Fpjd\Sso\SessionManager;
use Fpjd\Sso\Storage\FileStore;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Container\ContainerInterface;

return [
    // 1. Almacén de caché y cerrojos por archivo
    Store::class => function () {
        $cacheDir = __DIR__ . '/../../storage/cache/sso';
        return new FileStore($cacheDir);
    },

    // 2. Cifrador autenticado AES-256-GCM
    TokenCipher::class => function () {
        $rawKey = $_ENV['SSO_TOKEN_CIPHER_KEY'];
        if (str_starts_with($rawKey, 'base64:')) {
            $rawKey = base64_decode(substr($rawKey, 7));
        }
        return new TokenCipher($rawKey);
    },

    // 3. Cliente de comunicación HTTP con SSO
    Client::class => function (ContainerInterface $c) {
        return new Client(
            store: $c->get(Store::class),
            baseUrl: $_ENV['SSO_BASE_URL'],
            clientId: $_ENV['SSO_CLIENT_ID'],
            clientSecret: $_ENV['SSO_CLIENT_SECRET'],
        );
    },

    // 4. Gestor de sesiones multi-cuenta y cookies
    SessionManager::class => function (ContainerInterface $c) {
        return new SessionManager(
            cipher: $c->get(TokenCipher::class),
            cookiePrefix: $_ENV['COOKIE_PREFIX'] ?? 'fpjd',
            secure: ($_ENV['APP_ENV'] ?? 'production') === 'production',
        );
    },

    // 5. Autenticador agnóstico de solicitudes
    Authenticator::class => function (ContainerInterface $c) {
        return new Authenticator(
            client: $c->get(Client::class),
            sessions: $c->get(SessionManager::class),
            cipher: $c->get(TokenCipher::class),
            store: $c->get(Store::class),
        );
    },
];
```

### Inicialización de la Conexión de Base de Datos Eloquent (`auth`)

En tu bootstrap de Eloquent Capsule:

```php
use Fpjd\Sso\Database\AuthConnection;
use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule();

// Conexión por defecto de tu aplicación (ej: cortesias)
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'],
    'database'  => $_ENV['DB_DATABASE'],
    // ...
], 'default');

// AuthConnection la conexión reservada 'auth' para los modelos de fpjd/sso
AuthConnection::register($capsule, [
    'host'      => $_ENV['DB_AUTH_HOST'],
    'port'      => $_ENV['DB_AUTH_PORT'] ?? '3306',
    'database'  => $_ENV['DB_AUTH_DATABASE'],
    'username'  => $_ENV['DB_AUTH_USERNAME'],
    'password'  => $_ENV['DB_AUTH_PASSWORD'],
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
]);

$capsule->setAsGlobal();
$capsule->bootEloquent();
```

---

## 4. Implementación de `AuthController`

En tu aplicación Slim 4 o framework PSR-7:

```php
namespace App\Controllers;

use Fpjd\Sso\Client;
use Fpjd\Sso\Exceptions\GrantException;
use Fpjd\Sso\SessionManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    public function __construct(
        private Client $accounts,
        private SessionManager $sessions,
    ) {}

    /**
     * Inicia el flujo OAuth2 Authorization Code + PKCE
     */
    public function login(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $selectAccount = ($params['prompt'] ?? '') === 'select_account';
        $loginHint = $params['login_hint'] ?? null;
        $returnTo = $params['return_to'] ?? '/';

        $state = bin2hex(random_bytes(16));
        $verifier = Client::generateCodeVerifier();
        $challenge = Client::generateCodeChallenge($verifier);

        $url = $this->accounts->authorizeUrl(
            state: $state,
            codeChallenge: $challenge,
            selectAccount: $selectAccount,
            loginHint: $loginHint,
        );

        $response = $this->sessions->issueFlow($response, $state, [
            'v' => $verifier,
            'r' => $returnTo,
        ]);

        return $response->withHeader('Location', $url)->withStatus(302);
    }

    /**
     * Procesa el callback de SSO con el 'code' y 'state'
     */
    public function callback(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $code = $params['code'] ?? null;
        $state = $params['state'] ?? null;

        if (!$code || !$state) {
            return $this->error($response, 400, 'Parámetros de autorización ausentes.');
        }

        $flow = $this->sessions->readFlow($request, $state);
        if (!$flow) {
            return $this->error($response, 400, 'Flujo de autorización expirado o no encontrado.');
        }

        $response = $this->sessions->forgetFlow($response, $state);

        try {
            $tokens = $this->accounts->exchangeCode($code, $flow['v']);
        } catch (GrantException $e) {
            return $this->error($response, 401, 'Error al canjear el código de autorización: ' . $e->getMessage());
        }

        // Almacenar sesión de la cuenta en cookies seguras
        $response = $this->sessions->store($request, $response, $tokens, makeLast: true);

        $target = $flow['r'] ?: '/';
        return $response->withHeader('Location', $target)->withStatus(302);
    }

    /**
     * Devuelve el usuario activo y resumen de cuentas abiertas
     */
    public function me(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user'); // claims del JWT
        $activeId = $request->getAttribute('account_id');

        $payload = json_encode([
            'authenticated' => true,
            'account_id'    => $activeId,
            'user'          => $user,
            'accounts'      => $this->sessions->summary($request, $activeId),
        ], JSON_UNESCAPED_UNICODE);

        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Cierra la sesión activa
     */
    public function logout(Request $request, Response $response): Response
    {
        $accountId = $request->getAttribute('account_id');
        $session = $this->sessions->find($request, $accountId);

        if ($session && !empty($session['r'])) {
            $this->accounts->revoke($session['r'], endSession: true);
        }

        $response = $this->sessions->forget($request, $response, $accountId);

        $payload = json_encode(['success' => true]);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function error(Response $response, int $status, string $message): Response
    {
        $payload = json_encode(['error' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload);
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
```

---

## 5. Implementación de `AuthMiddleware` (PSR-15)

El middleware valida el token mediante `Authenticator`, renueva con mutex y relevo en caché si expiró, y adjunta la información de sesión a los atributos del `$request`:

```php
namespace App\Middlewares;

use Fpjd\Sso\Exceptions\SessionExpiredException;
use Fpjd\Sso\Authenticator;
use Fpjd\Sso\Exceptions\UnauthenticatedException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Authenticator $authenticator,
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        try {
            $authResult = $this->authenticator->authenticate($request);
        } catch (UnauthenticatedException $e) {
            return $this->errorResponse(401, 'UNAUTHENTICATED', $e->getMessage());
        } catch (SessionExpiredException $e) {
            return $this->errorResponse(401, 'SESSION_EXPIRED', $e->getMessage());
        } catch (\Throwable $e) {
            return $this->errorResponse(401, 'AUTH_ERROR', 'No fue posible validar la sesión: ' . $e->getMessage());
        }

        // Inyectar datos en atributos de la petición para controladores posteriores
        $request = $request
            ->withAttribute('user', $authResult->claims)
            ->withAttribute('account_id', $authResult->accountId)
            ->withAttribute('auth_result', $authResult);

        // Procesar siguiente middleware o controlador
        $response = $handler->handle($request);

        // Si el token fue renovado en segundo plano, adjuntar las nuevas cookies a la respuesta
        if ($authResult->renewedTokens !== null) {
            $response = $this->authenticator->withRenewal($request, $response, $authResult->renewedTokens);
        }

        return $response;
    }

    private function errorResponse(int $status, string $code, string $message): Response
    {
        $payload = json_encode([
            'error'   => true,
            'code'    => $code,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);

        $response = new SlimResponse($status);
        $response->getBody()->write($payload);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
```

---

## 6. Uso de Modelos Eloquent en la Aplicación

Para vincular entidades de tu aplicación con usuarios o empresas del SSO, utiliza relaciones Eloquent normales apuntando a los modelos de `Fpjd\Sso\Models`:

```php
namespace App\Models;

use Fpjd\Sso\Models\Account;
use Fpjd\Sso\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourtesyRequest extends Model
{
    protected $table = 'courtesy_requests';

    /**
     * Relación con el usuario del SSO (conexión 'auth')
     */
    public function authUser(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'user_auth_id', 'id');
    }

    /**
     * Relación con la empresa del SSO (conexión 'auth')
     */
    public function authCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }
}
```
