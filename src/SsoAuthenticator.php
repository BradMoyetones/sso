<?php

declare(strict_types=1);

namespace Fpjd\Sso;

use Firebase\JWT\ExpiredException;
use Fpjd\Sso\Exceptions\AccountsGrantException;
use Fpjd\Sso\Exceptions\AccountsUnavailableException;
use Fpjd\Sso\Exceptions\InvalidTokenException;
use Fpjd\Sso\Exceptions\SessionExpiredException;
use Fpjd\Sso\Exceptions\UnauthenticatedException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Orquestador central para la autenticación de peticiones HTTP en middlewares.
 *
 * Se encarga de:
 * 1. Resolver la cuenta requerida (cabecera X-Account o última sesión activa).
 * 2. Verificar la validez y firma del JWT.
 * 3. Gestionar la renovación transparente del access token con refresh token
 *    usando mutex (lock) y relevo en caché para peticiones simultáneas.
 * 4. Lanzar excepciones semánticas que el middleware de la aplicación puede
 *    capturar para devolver sus propias respuestas de error.
 */
class SsoAuthenticator
{
    private StoreInterface $store;

    public function __construct(
        private AccountsClient $accounts,
        private AccountSessions $sessions,
        private TokenCipher $cipher,
        ?StoreInterface $store = null,
    ) {
        $this->store = $store ?? new FileStore();
    }

    /**
     * Valida la petición HTTP y devuelve los datos de la sesión autenticada.
     *
     * @throws UnauthenticatedException Si no hay sesión en las cookies
     * @throws SessionExpiredException Si la sesión venció o fue revocada en Accounts
     * @throws InvalidTokenException Si el token fue manipulado o no coincide con la cookie
     * @throws AccountsUnavailableException Si el servidor SSO o el lock de concurrencia no responden
     */
    public function authenticate(Request $request): SsoAuthResult
    {
        $session = $this->sessions->find($request, $this->sessions->requested($request));

        if (!$session) {
            throw new UnauthenticatedException('No hay una sesión activa.');
        }

        $renewed = null;

        try {
            try {
                $claims = $this->accounts->verify($session['a']);
            } catch (ExpiredException) {
                [$claims, $renewed] = $this->renew($session);
            }

            if (($claims->sub ?? null) !== $session['u']) {
                throw new InvalidTokenException('La cuenta de la cookie no coincide con el token.');
            }
        } catch (AccountsUnavailableException | InvalidTokenException $e) {
            throw $e;
        } catch (AccountsGrantException $e) {
            throw new SessionExpiredException(
                'Tu sesión terminó. Inicia sesión de nuevo.',
                0,
                $e,
                $session['u']
            );
        } catch (\Throwable $e) {
            throw new InvalidTokenException(
                'La sesión no es válida.',
                0,
                $e,
                $session['u']
            );
        }

        return new SsoAuthResult(
            claims: $claims,
            accountId: $session['u'],
            renewedTokens: $renewed,
            session: $session
        );
    }

    /**
     * Adjunta las cookies de sesión renovadas a la respuesta HTTP si ocurrió
     * una rotación de tokens durante la petición.
     */
    public function withRenewal(Request $request, Response $response, ?array $renewedTokens): Response
    {
        return $renewedTokens ? $this->sessions->store($request, $response, $renewedTokens) : $response;
    }

    /**
     * Expira las cookies de sesión para la cuenta dada.
     */
    public function forget(Request $request, Response $response, string $accountId): Response
    {
        return $this->sessions->forget($request, $response, $accountId);
    }

    /**
     * @param array{u:string, a:string, r:string} $session
     * @return array{0:object, 1:array<string, mixed>}
     */
    private function renew(array $session): array
    {
        $lock = $this->store->lock('refresh:' . $session['u']);

        if ($lock === null) {
            throw new AccountsUnavailableException('No se pudo obtener el cerrojo de concurrencia para renovación.');
        }

        try {
            $key = 'handoff:' . hash('sha256', $session['r']);
            $cached = $this->store->get($key);
            $plain = $cached !== null ? $this->cipher->decrypt($cached, 'handoff') : null;
            $tokens = $plain !== null ? json_decode($plain, true) : null;

            if (!is_array($tokens) || !isset($tokens['access_token'])) {
                $tokens = $this->accounts->refresh($session['r']);

                $this->store->put($key, $this->cipher->encrypt(json_encode($tokens), 'handoff'), 30);
            }
        } finally {
            $this->store->unlock($lock);
        }

        return [$this->accounts->verify($tokens['access_token']), $tokens];
    }
}
