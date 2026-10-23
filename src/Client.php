<?php

declare(strict_types=1);

namespace Fpjd\Sso;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Fpjd\Sso\Contracts\Store;
use Fpjd\Sso\Exceptions\GrantException;
use Fpjd\Sso\Exceptions\IntegrationException;
use Fpjd\Sso\Exceptions\ProvisioningException;
use Fpjd\Sso\Exceptions\UnavailableException;
use Fpjd\Sso\Storage\FileStore;

/**
 * Cliente HTTP oficial de Accounts (SSO).
 * Gestiona el flujo OAuth2 / OpenID Connect + PKCE, la verificación de tokens JWT
 * mediante JWKS público con caché, y la API de aprovisionamiento de usuarios.
 */
class Client
{
    private const JWKS_FRESH_TTL = 300;
    private const JWKS_STALE_TTL = 86400;
    private const JWKS_FORCED_REFETCH_INTERVAL = 60;
    /** Segundos antes del `exp` en que un token canjeado deja de reutilizarse desde la caché. */
    private const EXCHANGE_MARGIN = 30;

    private Store $store;

    public function __construct(
        ?Store $store = null,
        private ?string $baseUrl = null,
        private ?string $clientId = null,
        private ?string $clientSecret = null,
        private ?string $issuer = null
    ) {
        $this->store = $store ?? new FileStore();
    }

    /**
     * Construye la URL de redirección a Accounts (/authorize) con PKCE.
     *
     * @param string|null $loginHint UUID de la cuenta que se desea preseleccionar.
     */
    public function authorizeUrl(
        string $state,
        string $codeChallenge,
        bool $selectAccount = false,
        ?string $loginHint = null
    ): string {
        $params = [
            'client_id' => $this->clientId(),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        if ($selectAccount) {
            $params['prompt'] = 'select_account';
        }

        if ($loginHint !== null) {
            $params['login_hint'] = $loginHint;
        }

        return $this->baseUrl() . '/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Canjea el código de autorización por el par de tokens (access_token, refresh_token).
     *
     * @return array<string, mixed>
     * @throws GrantException si el código es inválido o expiró
     * @throws UnavailableException si Accounts no responde
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        return $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
        ]);
    }

    /**
     * Rota y renueva los tokens utilizando el refresh token.
     *
     * @return array<string, mixed>
     * @throws GrantException si el refresh token fue revocado o venció
     */
    public function refresh(string $refreshToken): array
    {
        return $this->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Revoca el refresh token en Accounts y opcionalmente cierra la sesión global.
     */
    public function revoke(string $refreshToken, bool $endSession = true): void
    {
        try {
            $params = ['refresh_token' => $refreshToken];
            if ($endSession) {
                $params['end_session'] = '1';
            }
            $this->post('/api/logout', $params);
        } catch (\Throwable) {
            // Best effort: el cierre de sesión local no debe fallar si Accounts no responde.
        }
    }

    /**
     * Consulta pasiva sobre si la sesión de la cuenta sigue activa en Accounts (fail-open).
     * Devuelve null si no se pudo determinar el estado.
     */
    public function sessionActive(string $refreshToken): ?bool
    {
        try {
            [$status, $data] = $this->post('/api/session/check', ['refresh_token' => $refreshToken], 3);
        } catch (\Throwable) {
            return null;
        }

        return $status === 200 && is_bool($data['active'] ?? null) ? $data['active'] : null;
    }

    // ─── Aprovisionamiento de Usuarios (API Basic Auth) ────────────────────────

    /**
     * Registra o vincula una persona en Accounts y le otorga acceso a esta aplicación.
     *
     * @param array{identification:string, first_name:string, last_name:string, email?:?string, phone?:?string, management_id?:?string, is_external?:bool} $person
     *        `is_external` solo aplica si la persona se crea: marca la cuenta como externa (sin gestión).
     * @return array{user: array<string, mixed>, created: bool, temporary_password: ?string, access_granted: bool, management_assigned: bool}
     */
    public function provisionUser(array $person, ?string $actedBy = null): array
    {
        return $this->provisioning('/api/users', $person + ['acted_by' => $actedBy]);
    }

    /**
     * Otorga o reactiva el acceso de un usuario a esta aplicación.
     *
     * @return array{user: array<string, mixed>, access_granted: bool}
     */
    public function grantAccess(string $accountId, ?string $actedBy = null): array
    {
        return $this->provisioning("/api/users/$accountId/access", ['acted_by' => $actedBy]);
    }

    /**
     * Revoca el acceso de un usuario a esta aplicación específica.
     *
     * @return array{user: array<string, mixed>, access_revoked: bool}
     */
    public function revokeAccess(string $accountId, ?string $actedBy = null): array
    {
        return $this->provisioning("/api/users/$accountId/access/revoke", ['acted_by' => $actedBy]);
    }

    /**
     * Asigna o remueve (con null) el área de gestión de una persona en Accounts.
     *
     * @return array{user: array<string, mixed>}
     */
    public function setManagement(string $accountId, ?string $managementId, ?string $actedBy = null): array
    {
        return $this->provisioning("/api/users/$accountId/management", [
            'management_id' => $managementId,
            'acted_by' => $actedBy,
        ]);
    }

    /**
     * Consulta las aplicaciones disponibles para un usuario en el portal del parque.
     *
     * @return array{portal_url: string, apps: list<array<string, mixed>>}
     */
    public function userApps(string $accountId): array
    {
        return $this->provisioning("/api/users/$accountId/apps", [], 'GET');
    }

    // ─── Integraciones entre apps (token exchange, RFC 8693) ───────────────────

    /**
     * Canjea el access token de sesión del usuario (emitido para ESTA app) por uno para la API
     * de `$audience`, con los scopes que la integración tenga otorgados en Accounts. Se cachea
     * hasta poco antes de vencer: llamarlo en cada petición no golpea a Accounts.
     *
     * @param string[] $scopes los que se piden; Accounts devuelve solo los otorgados
     * @return array{access_token: string, expires_in: int, scope: string[]}
     * @throws GrantException si la sesión del usuario ya no es válida (hay que iniciar sesión)
     * @throws IntegrationException si la integración no está habilitada (responder 403)
     * @throws UnavailableException si Accounts no responde
     */
    public function exchangeToken(string $subjectToken, string $audience, array $scopes): array
    {
        sort($scopes);
        $cacheKey = 'xchg:' . hash('sha256', $subjectToken . '|' . $audience . '|' . implode(' ', $scopes));

        $cached = $this->store->get($cacheKey);
        $cached = $cached !== null ? json_decode($cached, true) : null;

        if (is_array($cached) && is_string($cached['access_token'] ?? null)) {
            return $cached;
        }

        [$status, $data] = $this->post('/api/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $subjectToken,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'audience' => $audience,
            'scope' => implode(' ', $scopes),
        ]);

        if ($status === 200 && is_string($data['access_token'] ?? null)) {
            $result = [
                'access_token' => $data['access_token'],
                'expires_in' => (int) ($data['expires_in'] ?? 0),
                'scope' => array_values(array_filter(explode(' ', (string) ($data['scope'] ?? '')))),
            ];

            // Margen para que el token no venza en camino a la API destino.
            if ($result['expires_in'] > self::EXCHANGE_MARGIN) {
                $this->store->put($cacheKey, (string) json_encode($result), $result['expires_in'] - self::EXCHANGE_MARGIN);
            }

            return $result;
        }

        $description = (string) ($data['error_description'] ?? $data['error'] ?? 'token-exchange rechazado');

        if ($status === 400 && in_array($data['error'] ?? null, ['invalid_scope', 'invalid_target'], true)) {
            throw new IntegrationException($description);
        }

        if ($status === 400) {
            throw new GrantException($description);
        }

        error_log("[accounts-sso] token-exchange respondió $status: " . json_encode($data));

        throw new UnavailableException("El servidor de autenticación respondió de forma errónea ($status).");
    }

    /**
     * Valida un token de integración recibido por la API de ESTA app (`Authorization: Bearer`):
     * firma, expiración, emisor, audiencia y que sea delegado (`act`). `sub` es la persona,
     * `act.sub` la app que actúa en su nombre y `scope` lo que puede hacer.
     *
     * @throws \Firebase\JWT\ExpiredException si el token venció
     * @throws \Throwable si la firma o los claims son inválidos
     */
    public function verifyDelegated(string $jwt): object
    {
        $claims = $this->decode($jwt);

        if (!is_string($claims->act->sub ?? null) || !is_string($claims->scope ?? null)) {
            throw new \UnexpectedValueException('No es un token de integración (act/scope).');
        }

        return $claims;
    }

    /**
     * Valida la firma criptográfica (RS256 con JWKS), expiración, emisor y audiencia del JWT
     * de una sesión. Rechaza los tokens de integración: esos solo sirven por `verifyDelegated`.
     *
     * @throws \Firebase\JWT\ExpiredException si el token venció
     * @throws \Throwable si la firma o los claims son inválidos
     */
    public function verify(string $jwt): object
    {
        $claims = $this->decode($jwt);

        if (isset($claims->act)) {
            throw new \UnexpectedValueException('Un token de integración no abre sesión.');
        }

        return $claims;
    }

    private function decode(string $jwt): object
    {
        JWT::$leeway = 30;

        $keys = $this->keySet();
        $kid = $this->headerKid($jwt);

        if ($kid !== null && !isset($keys[$kid])) {
            $keys = $this->keySet(force: true);
        }

        $claims = JWT::decode($jwt, $keys);

        $expectedIssuer = $this->issuer();
        if ($expectedIssuer !== null && ($claims->iss ?? null) !== $expectedIssuer) {
            throw new \UnexpectedValueException('Emisor de token (iss) inválido.');
        }

        if (($claims->aud ?? null) !== $this->clientId()) {
            throw new \UnexpectedValueException('Audiencia de token (aud) inválida.');
        }

        return $claims;
    }

    // ─── Peticiones HTTP Internas ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function token(array $params): array
    {
        [$status, $data] = $this->post('/api/token', $params);

        if ($status === 200 && isset($data['access_token'], $data['refresh_token'], $data['user']['id'])) {
            return $data;
        }

        if ($status === 400) {
            throw new GrantException($data['error_description'] ?? 'invalid_grant');
        }

        error_log("[accounts-sso] /api/token respondió $status: " . json_encode($data));

        throw new UnavailableException("El servidor de autenticación respondió de forma errónea ($status).");
    }

    /**
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function post(string $path, array $params, int $timeout = 10): array
    {
        $ch = curl_init($this->baseUrl() . $path);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params + [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        $data = is_string($body) ? json_decode($body, true) : null;

        return [$status, is_array($data) ? $data : []];
    }

    /**
     * @return array<string, mixed>
     */
    private function provisioning(string $path, array $payload, string $method = 'POST'): array
    {
        $payload = array_filter(
            $payload,
            fn ($v, $k) => $v !== null || $k === 'management_id',
            ARRAY_FILTER_USE_BOTH
        );

        $ch = curl_init($this->baseUrl() . $path);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode(rawurlencode($this->clientId()) . ':' . rawurlencode($this->clientSecret())),
            ],
        ];

        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $data = is_string($body) ? json_decode($body, true) : null;
        $data = is_array($data) ? $data : [];

        if ($status >= 200 && $status < 300) {
            return $data;
        }

        if (in_array($status, [404, 409, 422, 429], true) || ($status === 403 && ($data['error'] ?? '') === 'no_access')) {
            throw new ProvisioningException(
                $status,
                (string) ($data['error'] ?? 'error'),
                (string) ($data['error_description'] ?? 'Accounts rechazó la operación.'),
                is_array($data['errors'] ?? null) ? $data['errors'] : []
            );
        }

        error_log("[accounts-sso] aprovisionamiento $path respondió $status: " . json_encode($data));

        throw new UnavailableException("No fue posible procesar la operación de aprovisionamiento ($status).");
    }

    // ─── JWKS y Claves Públicas ────────────────────────────────────────────────

    /**
     * @return array<string, Key>
     */
    private function keySet(bool $force = false): array
    {
        $fresh = $this->store->get('jwks:fresh');

        if ($force) {
            if ($this->store->get('jwks:forced') !== null) {
                $fresh = $this->store->get('jwks:fresh') ?? $this->store->get('jwks:stale');
            } else {
                $this->store->put('jwks:forced', '1', self::JWKS_FORCED_REFETCH_INTERVAL);
                $fresh = null;
            }
        }

        if ($fresh === null) {
            $fresh = $this->fetchJwks();

            if ($fresh === null && ($fresh = $this->store->get('jwks:stale')) !== null) {
                $this->store->put('jwks:fresh', $fresh, 30);
            }
        }

        $jwks = $fresh !== null ? json_decode($fresh, true) : null;

        if (!is_array($jwks) || empty($jwks['keys'])) {
            throw new UnavailableException('No se pudo obtener el conjunto de claves JWKS de Accounts.');
        }

        return JWK::parseKeySet($jwks, 'RS256');
    }

    private function fetchJwks(): ?string
    {
        $ch = curl_init($this->baseUrl() . '/.well-known/jwks.json');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        $decoded = is_string($body) ? json_decode($body, true) : null;

        if ($status !== 200 || !is_array($decoded) || empty($decoded['keys'])) {
            return null;
        }

        $this->store->put('jwks:fresh', $body, self::JWKS_FRESH_TTL);
        $this->store->put('jwks:stale', $body, self::JWKS_STALE_TTL);

        return $body;
    }

    private function headerKid(string $jwt): ?string
    {
        $header = json_decode((string) base64_decode(strtr(explode('.', $jwt)[0], '-_', '+/'), true), true);

        return is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->baseUrl ?? ($_ENV['ACCOUNTS_URL'] ?? '')), '/');
    }

    private function clientId(): string
    {
        return (string) ($this->clientId ?? ($_ENV['ACCOUNTS_CLIENT_ID'] ?? ''));
    }

    private function clientSecret(): string
    {
        return (string) ($this->clientSecret ?? ($_ENV['ACCOUNTS_CLIENT_SECRET'] ?? ''));
    }

    private function issuer(): ?string
    {
        return $this->issuer ?? ($_ENV['ACCOUNTS_ISSUER'] ?? null);
    }

    // ─── Utilidades Criptográficas y PKCE ──────────────────────────────────────

    /**
     * Genera un code_verifier aleatorio para PKCE (RFC 7636).
     */
    public static function generateCodeVerifier(int $bytes = 48): string
    {
        return self::base64Url(random_bytes($bytes));
    }

    /**
     * Calcula el code_challenge S256 a partir del code_verifier.
     */
    public static function generateCodeChallenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    /**
     * Codificación Base64 URL-safe sin padding '=' (RFC 4648 §5).
     */
    public static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Sanitiza una ruta de redirección interna evitando Open Redirects.
     */
    public static function safeReturnPath(?string $path, string $fallback = '/'): string
    {
        if (!is_string($path) || $path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $fallback;
        }

        return $path;
    }
}
