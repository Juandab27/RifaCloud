<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Admin;

use PDO;
use RuntimeException;

/**
 * Validates an administrator identity at the raffles-service boundary.
 *
 * Security model (ADR-001 §4):
 *  - The identity is never taken from a client-supplied header.
 *  - The Laravel session cookie is decrypted with the shared APP_KEY and its
 *    HMAC/prefix are verified.
 *  - The session id must exist in the shared `sessions` table and not be
 *    expired.
 *  - The linked user must still have role ADMIN.
 *  - The password hash stored in the session must match the current password
 *    (Laravel's AuthenticateSession parity, so password changes revoke access).
 *
 * The service only reads `sessions` and `users`; it never writes them.
 */
final class AdminSessionAuthenticator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $appKey,
        private readonly string $cookieName = 'rifacloud-session',
        private readonly int $lifetimeMinutes = 120,
        private readonly string $sessionTable = 'sessions',
    ) {}

    /**
     * Loads the session context without enforcing a role.
     *
     * Returning null is the single "no authenticated user" outcome for every
     * malformed, tampered, expired or orphaned session.
     *
     * @param  array<string, mixed>  $cookies
     */
    public function load(array $cookies): ?AdminSessionContext
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

        return new AdminSessionContext(
            (int) $user['id'],
            (string) $user['name'],
            (string) $user['email'],
            (string) $user['role'],
            SessionPayload::csrfToken($payload) ?? '',
            $this->passwordHashMatches($user, $payload),
        );
    }

    /**
     * @throws AdminAuthException
     */
    public function requireAdmin(?AdminSessionContext $context): AdminSession
    {
        if ($context === null) {
            throw AdminAuthException::unauthenticated();
        }

        if (! $context->passwordMatches) {
            // Password changed after the session was issued: the monolith
            // would log the device out on the next request.
            throw AdminAuthException::unauthenticated();
        }

        if ($context->role !== 'ADMIN') {
            throw AdminAuthException::forbidden();
        }

        return new AdminSession(
            $context->userId,
            $context->name,
            $context->email,
            $context->csrfToken,
        );
    }

    /**
     * Laravel's PreventRequestForgery parity.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     *
     * @throws CsrfTokenMismatchException
     */
    public function assertCsrf(
        string $method,
        array $headers,
        array $body,
        ?AdminSessionContext $context,
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
}
