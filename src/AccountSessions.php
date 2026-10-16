<?php

declare(strict_types=1);

namespace Fpjd\Sso;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Gestor de sesiones multi-cuenta en cookies HttpOnly host-only.
 *
 * Mantiene una cookie por cada cuenta activa (`<prefix>_a_<id>`), una cookie
 * con la última cuenta utilizada (`<prefix>_last`) y una cookie de flujo por intento
 * de login (`<prefix>_f_<state>`).
 */
class AccountSessions
{
    public const MAX_ACCOUNTS = 3;
    private const FLOW_TTL = 600;

    public function __construct(
        private TokenCipher $cipher,
        private ?string $cookiePrefix = null,
        private ?bool $secure = null
    ) {
    }

    // ─── Gestión de Cuentas Activas ───────────────────────────────────────────

    /**
     * Retorna todas las sesiones válidas presentes en las cookies de la petición.
     *
     * @return array<string, array{u:string, a:string, r:string, n:string, e:?string, t:int}>
     */
    public function all(Request $request): array
    {
        $accounts = [];
        $prefix = $this->prefix() . '_a_';

        foreach ($request->getCookieParams() as $name => $value) {
            if (!is_string($name) || !str_starts_with($name, $prefix) || !is_string($value)) {
                continue;
            }

            $plain = $this->cipher->decrypt($value, 'account');
            $data = $plain !== null ? json_decode($plain, true) : null;

            if (
                is_array($data)
                && is_string($data['u'] ?? null)
                && is_string($data['a'] ?? null)
                && is_string($data['r'] ?? null)
                && $name === $this->accountCookie($data['u'])
            ) {
                $accounts[$data['u']] = $data;
            }
        }

        return $accounts;
    }

    /**
     * Obtiene la sesión de la cuenta especificada. Si no se pasa id,
     * retorna la última utilizada o la única existente.
     *
     * @return array{u:string, a:string, r:string, n:string, e:?string, t:int}|null
     */
    public function find(Request $request, ?string $userId): ?array
    {
        $accounts = $this->all($request);

        if ($userId !== null) {
            return $accounts[$userId] ?? null;
        }

        $last = $this->lastUsed($request);

        if ($last !== null && isset($accounts[$last])) {
            return $accounts[$last];
        }

        return count($accounts) === 1 ? reset($accounts) : null;
    }

    public function lastUsed(Request $request): ?string
    {
        $value = $request->getCookieParams()[$this->prefix() . '_last'] ?? null;

        return is_string($value) && preg_match('/^[0-9a-f-]{36}$/i', $value) ? $value : null;
    }

    /**
     * Almacena o actualiza la cookie de sesión para una cuenta con los tokens obtenidos.
     *
     * @param array<string, mixed> $tokens Respuesta de /api/token
     * @param bool $makeLast Si se debe marcar como la última cuenta seleccionada
     */
    public function store(Request $request, Response $response, array $tokens, bool $makeLast = false): Response
    {
        $user = $tokens['user'] ?? [];
        $id = (string) ($user['id'] ?? '');

        if ($id === '') {
            return $response;
        }

        $existing = $this->all($request);

        // Si se supera el límite de cuentas, se desaloja la menos recientemente usada.
        if (!isset($existing[$id]) && count($existing) >= self::MAX_ACCOUNTS) {
            uasort($existing, fn ($a, $b) => $a['t'] <=> $b['t']);

            $toEvictCount = count($existing) - self::MAX_ACCOUNTS + 1;
            foreach (array_slice(array_keys($existing), 0, $toEvictCount) as $evict) {
                $response = $this->expire($response, $this->accountCookie($evict));
            }
        }

        $payload = json_encode([
            'u' => $id,
            'a' => $tokens['access_token'],
            'r' => $tokens['refresh_token'],
            'n' => (string) ($user['name'] ?? ''),
            'e' => $user['email'] ?? null,
            't' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ttl = max(60, (int) ($tokens['refresh_expires_in'] ?? 86400));

        $response = $this->issue(
            $response,
            $this->accountCookie($id),
            $this->cipher->encrypt($payload, 'account'),
            $ttl
        );

        return $makeLast ? $this->issue($response, $this->prefix() . '_last', $id, 30 * 86400) : $response;
    }

    public function forget(Request $request, Response $response, string $userId): Response
    {
        $response = $this->expire($response, $this->accountCookie($userId));

        return $this->lastUsed($request) === $userId ? $this->expire($response, $this->prefix() . '_last') : $response;
    }

    // ─── Selección por Pestaña / Cabecera X-Account ─────────────────────────────

    /**
     * Cuenta solicitada explícitamente por esta pestaña mediante cabecera X-Account.
     */
    public function requested(Request $request): ?string
    {
        $value = trim($request->getHeaderLine('X-Account'));

        return preg_match('/^[0-9a-f-]{36}$/i', $value) ? strtolower($value) : null;
    }

    /**
     * Resumen de cuentas abiertas para el selector de cuentas en la interfaz.
     *
     * @return list<array{id:string, name:string, email:?string, active:bool}>
     */
    public function summary(Request $request, ?string $activeId): array
    {
        $accounts = $this->all($request);
        uasort($accounts, fn ($a, $b) => $b['t'] <=> $a['t']);

        return array_values(array_map(fn ($a) => [
            'id' => $a['u'],
            'name' => $a['n'],
            'email' => $a['e'],
            'active' => $a['u'] === $activeId,
        ], $accounts));
    }

    // ─── Flujo de Autorización PKCE (Cookies de Flujo) ─────────────────────────

    /**
     * @param array{v:string, r:string} $flow
     */
    public function issueFlow(Response $response, string $state, array $flow): Response
    {
        return $this->issue(
            $response,
            $this->flowCookie($state),
            $this->cipher->encrypt(json_encode($flow + ['t' => time()]), 'flow:' . $state),
            self::FLOW_TTL
        );
    }

    /**
     * @return array{v:string, r:string}|null
     */
    public function readFlow(Request $request, string $state): ?array
    {
        $value = $request->getCookieParams()[$this->flowCookie($state)] ?? null;
        $plain = is_string($value) ? $this->cipher->decrypt($value, 'flow:' . $state) : null;
        $data = $plain !== null ? json_decode($plain, true) : null;

        if (
            !is_array($data)
            || !is_string($data['v'] ?? null)
            || !is_string($data['r'] ?? null)
            || time() - (int) ($data['t'] ?? 0) > self::FLOW_TTL
        ) {
            return null;
        }

        return $data;
    }

    public function forgetFlow(Response $response, string $state): Response
    {
        return $this->expire($response, $this->flowCookie($state));
    }

    // ─── Helpers Internos de Cookies ───────────────────────────────────────────

    private function accountCookie(string $userId): string
    {
        return $this->prefix() . '_a_' . substr(hash('sha256', $userId), 0, 12);
    }

    private function flowCookie(string $state): string
    {
        return $this->prefix() . '_f_' . substr(hash('sha256', $state), 0, 16);
    }

    private function prefix(): string
    {
        $base = $this->cookiePrefix
            ?? ($_ENV['COOKIE_PREFIX'] ?? null)
            ?? ($_ENV['APP_NAME'] ?? 'fpjd');

        $name = preg_replace('/[^A-Za-z0-9]/', '', (string) $base) ?: 'fpjd';

        return ($this->secure() ? '__Host-' : '') . $name;
    }

    private function secure(): bool
    {
        if ($this->secure !== null) {
            return $this->secure;
        }

        return ($_ENV['APP_ENV'] ?? 'dev') === 'production';
    }

    private function issue(Response $response, string $name, string $value, int $ttl): Response
    {
        return $response->withAddedHeader('Set-Cookie', $this->build($name, $value, time() + $ttl, $ttl));
    }

    private function expire(Response $response, string $name): Response
    {
        return $response->withAddedHeader('Set-Cookie', $this->build($name, '', time() - 3600, 0));
    }

    private function build(string $name, string $value, int $expires, int $maxAge): string
    {
        $parts = [
            "$name=$value",
            'Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT',
            'Max-Age=' . max(0, $maxAge),
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        ];

        if ($this->secure()) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
