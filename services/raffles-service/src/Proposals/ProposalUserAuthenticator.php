<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Proposals;

use PDO;
use RifaCloud\RafflesService\Admin\AdminAuthException;
use RifaCloud\RafflesService\Admin\AdminSessionAuthenticator;
use RifaCloud\RafflesService\Admin\AdminSessionContext;
use RifaCloud\RafflesService\Admin\CsrfTokenMismatchException;

/**
 * Resolves the authenticated proposer from the shared Laravel session.
 *
 * It reuses the hardened session authenticator (encrypted cookie, session
 * table, password marker) and then reads the contact fields from `users`.
 * The service only reads `sessions`/`users`; proposals are the only writes.
 */
final class ProposalUserAuthenticator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AdminSessionAuthenticator $sessions,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $body
     *
     * @throws AdminAuthException
     * @throws CsrfTokenMismatchException
     */
    public function authenticate(
        string $method,
        array $headers,
        array $cookies,
        array $body,
    ): ProposalUser {
        $context = $this->sessions->load($cookies);

        // No session is always 401, even before CSRF, so an anonymous client
        // receives the documented authentication error.
        if ($context === null) {
            throw AdminAuthException::unauthenticated();
        }

        $this->sessions->assertCsrf($method, $headers, $body, $context);

        return $this->requireUser($context);
    }

    /**
     * @throws AdminAuthException
     */
    private function requireUser(AdminSessionContext $context): ProposalUser
    {
        if (! $context->passwordMatches) {
            // Password changed after the session was issued.
            throw AdminAuthException::unauthenticated();
        }

        $statement = $this->pdo->prepare(
            'SELECT id, name, email, phone FROM users WHERE id = :id LIMIT 1',
        );
        $statement->execute([':id' => $context->userId]);
        $user = $statement->fetch();

        if ($user === false) {
            throw AdminAuthException::unauthenticated();
        }

        return new ProposalUser(
            (int) $user['id'],
            (string) $user['name'],
            (string) $user['email'],
            (string) $user['phone'],
            $context->csrfToken,
        );
    }
}
