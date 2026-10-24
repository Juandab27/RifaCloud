<?php

namespace App\Modules\Raffles\Enums;

enum RaffleStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case CLOSED = 'CLOSED';
    case FINISHED = 'FINISHED';

    /**
     * Allowed status transitions for admin actions (FR-012).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::PUBLISHED],
            self::PUBLISHED => [self::CLOSED],
            self::CLOSED, self::FINISHED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
