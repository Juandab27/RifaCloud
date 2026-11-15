<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Proposals;

use RuntimeException;

/**
 * Moderation conflict: the proposal was already approved or rejected.
 */
final class ProposalConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La propuesta ya fue revisada por un administrador.');
    }
}
