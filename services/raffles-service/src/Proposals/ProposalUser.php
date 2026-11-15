<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Proposals;

/**
 * Authenticated proposer resolved from the shared session.
 *
 * The contact fields come from the users row the backend loaded, never from
 * request input.
 */
final class ProposalUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $csrfToken,
    ) {}
}
