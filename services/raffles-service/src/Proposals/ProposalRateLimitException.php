<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Proposals;

use RuntimeException;

/**
 * Per-user submission throttle. The response carries a Retry-After hint.
 */
final class ProposalRateLimitException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct($message);
    }

    public static function daily(int $seconds): self
    {
        return new self(
            'Alcanzaste el límite de propuestas por día. Inténtalo más tarde.',
            $seconds,
        );
    }

    public static function cooldown(int $seconds): self
    {
        return new self(
            'Espera unos segundos antes de enviar otra propuesta.',
            $seconds,
        );
    }
}
