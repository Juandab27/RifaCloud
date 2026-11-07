<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Auth;

use PDO;
use RuntimeException;

/**
 * Validates an authenticated customer identity at the orders-service boundary.
 *
 * Security model (ADR-001 §4):
 *  - The identity never comes from a client-supplied header.
 *  - The Laravel session cookie is decrypted with the shared APP_KEY and its
 *    HMAC/prefix are verified.
 *  - The session id must exist in the shared `sessions` table and not be
 *    expired.
 *  - The password hash stored in the session must match the current password
 *    (Laravel's AuthenticateSession parity, so password changes revoke access).
 *  - CSRF is enforced for stateful (browser-origin) unsafe requests.
 *
 * The service only reads `sessions` and `users`; it never writes them.
 */
final class UserSessionAuthenticator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $appKey,
        private readonly string $cookieName = 'rifacloud-session',
        private readonly int $lifetimeMinutes = 120,
        private readonly string $sessionTable = 'sessions',
    ) {}

    /**
     * @param  array<string, mixed>  $cookies
     */
    public function load(array $cookies): ?UserSessionContext
    {
        $raw = $cookies[$this->cookieName] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $sessionId = $this->sessionIdFromCookie($raw);

        if ($sessionId === null) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT user_id, payload, last_activity FROM %s WHERE id = :id LIMIT 1',
                $this->sessionTable,
            ),
        );
        $statement->execute([':id' => $sessionId]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $lastActivity = (int) $row['last_activity'];

        if ($lastActivity < time() - ($this->lifetimeMinutes * 60)) {
            return null;
        }

        $userId = $row['user_id'] === null ? null : (int) $row['user_id'];

        if ($userId === null || $userId <= 0) {
            return null;
        }

        $payload = SessionPayload::decode((string) $row['payload'], $this->appKey);

        $userStatement = $this->pdo->prepare(
            'SELECT id, name, email, role, password FROM users WHERE id = :id LIMIT 1',
        );
        $userStatement->execute([':id' => $userId]);
        $user = $userStatement->fetch();

        if ($user === false) {
            return null;
        }

        return new UserSessionContext(
            (int) $user['id'],
            (string) $user['name'],
            (string) $user['email'],
            (string) $user['role'],
            SessionPayload::csrfToken($payload) ?? '',
            $this->passwordHashMatches($user, $payload),
        );
    }

    /**
     * @throws AuthException
     */
    public function requireUser(?UserSessionContext $context): UserSessionContext
    {
        if ($context === null || ! $context->passwordMatches) {
            throw AuthException::unauthenticated();
        }

        return $context;
    }

    /**
     * Laravel/Sanctum parity: CSRF only applies to stateful requests. The SPA
     * sends Origin/Sec-Fetch-Site plus X-XSRF-TOKEN; stateless API clients are
     * rejected by authentication instead of by a CSRF error.
     *
     * @param  array<string, string>  $headers
     */
    public function isStatefulRequest(array $headers, string $baseUrl): bool
    {
        $host = $this->normalizedHost($headers['host'] ?? (string) parse_url($baseUrl, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach (['origin', 'referer'] as $header) {
            $value = $headers[$header] ?? null;

            if (is_string($value) && $value !== '') {
                $originHost = $this->normalizedHost((string) parse_url($value, PHP_URL_HOST));
                $originPort = parse_url($value, PHP_URL_PORT);
                $hostWithoutPort = $this->hostWithoutPort($host);
                $requestPort = $this->hostPort($host, $baseUrl);

                if (
                    $originHost !== ''
                    && $originHost === $hostWithoutPort
                    && ($originPort === null || (int) $originPort === $requestPort)
                ) {
                    return true;
                }
            }
        }

        return strtolower($headers['sec-fetch-site'] ?? '') === 'same-origin';
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     *
     * @throws CsrfTokenMismatchException
     */
    public function assertCsrf(
        string $method,
        array $headers,
        array $body,
        ?UserSessionContext $context,
    ): void {
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        // Browsers set this header; cross-site JavaScript cannot forge it.
        // Laravel 12 accepts same-origin requests without a token for the same
        // reason.
        if (strtolower($headers['sec-fetch-site'] ?? '') === 'same-origin') {
            return;
        }

        $token = $this->tokenFromRequest($headers, $body);
        $sessionToken = $context?->csrfToken ?? '';

        if (
            $token === null
            || $sessionToken === ''
            || ! hash_equals($sessionToken, $token)
        ) {
            throw new CsrfTokenMismatchException();
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    private function tokenFromRequest(array $headers, array $body): ?string
    {
        $bodyToken = $body['_token'] ?? null;

        if (is_string($bodyToken) && $bodyToken !== '') {
            return $bodyToken;
        }

        $headerToken = $headers['x-csrf-token'] ?? null;

        if (is_string($headerToken) && $headerToken !== '') {
            return $headerToken;
        }

        $xsrf = $headers['x-xsrf-token'] ?? null;

        if (! is_string($xsrf) || $xsrf === '') {
            return null;
        }

        try {
            $key = LaravelCrypto::key($this->appKey);
            $decrypted = LaravelCrypto::decrypt($xsrf, $key, false);
            $prefix = LaravelCrypto::cookiePrefix('XSRF-TOKEN', $key);

            if (! str_starts_with($decrypted, $prefix)) {
                return null;
            }

            $token = substr($decrypted, strlen($prefix));

            return $token === '' ? null : $token;
        } catch (RuntimeException) {
            return null;
        }
    }

    private function sessionIdFromCookie(string $raw): ?string
    {
        try {
            $key = LaravelCrypto::key($this->appKey);
            $decrypted = LaravelCrypto::decrypt($raw, $key, false);
            $prefix = LaravelCrypto::cookiePrefix($this->cookieName, $key);

            if (! str_starts_with($decrypted, $prefix)) {
                return null;
            }

            $sessionId = substr($decrypted, strlen($prefix));

            return preg_match('/^[A-Za-z0-9]{20,128}$/', $sessionId) === 1
                ? $sessionId
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $payload
     */
    private function passwordHashMatches(array $user, array $payload): bool
    {
        $stored = SessionPayload::passwordHash($payload);

        // Legacy session without the marker: Laravel would seed it on the next
        // request, so it is accepted here rather than locking the user out.
        if ($stored === null) {
            return true;
        }

        $passwordHash = (string) ($user['password'] ?? '');

        if ($passwordHash === '') {
            return false;
        }

        $hmac = hash_hmac('sha256', $passwordHash, $this->appKey);

        return hash_equals($hmac, $stored) || hash_equals($passwordHash, $stored);
    }

    private function normalizedHost(string $host): string
    {
        return strtolower(trim($host));
    }

    private function hostWithoutPort(string $host): string
    {
        $position = strrpos($host, ':');

        return $position === false ? $host : substr($host, 0, $position);
    }

    private function hostPort(string $host, string $baseUrl): int
    {
        $position = strrpos($host, ':');

        if ($position !== false) {
            $port = (int) substr($host, $position + 1);

            if ($port > 0) {
                return $port;
            }
        }

        return (int) (parse_url($baseUrl, PHP_URL_PORT) ?: 80);
    }
}
