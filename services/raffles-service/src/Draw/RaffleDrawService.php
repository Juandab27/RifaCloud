<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Draw;

use PDO;
use PDOException;
use Throwable;

/**
 * Automatic sales closure and winner selection.
 *
 * The draw is opt-in (draw_mode=AUTOMATIC). It locks the raffle row, verifies
 * that only confirmed sales are present, selects uniformly with random_int(),
 * writes the unique raffle_results row and finishes the raffle in one
 * transaction. It never changes numbers, orders or payments.
 */
final class RaffleDrawService
{
    public const DRAW_VERSION = 's12-v1';

    public const RANDOM_METHOD = 'php_random_int';

    public function __construct(
        private readonly PDO $pdo,
        private readonly RaffleDrawRepository $repository,
        private readonly int $bufferMinutes,
        private readonly int $batchLimit,
    ) {}

    /**
     * @return array{closed: int, drawn: int, blocked: int, skipped: int, errors: int}
     */
    public function processDue(?int $limit = null): array
    {
        $limit = $limit === null ? $this->batchLimit : max(1, min(100, $limit));
        $closed = $this->repository->closeDueSales($limit);
        $ids = $this->repository->findDueRaffleIds($limit);

        $drawn = 0;
        $blocked = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($ids as $raffleId) {
            $outcome = $this->drawOne($raffleId);

            match ($outcome) {
                'drawn' => $drawn++,
                'blocked' => $blocked++,
                'error' => $errors++,
                default => $skipped++,
            };
        }

        return [
            'closed' => $closed,
            'drawn' => $drawn,
            'blocked' => $blocked,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @return 'drawn'|'blocked'|'skipped'|'error'
     */
    private function drawOne(int $raffleId): string
    {
        $this->pdo->beginTransaction();

        try {
            $raffle = $this->repository->findForUpdate($raffleId);

            if ($raffle === null) {
                $this->pdo->commit();

                return 'skipped';
            }

            if (
                (string) $raffle['status'] === 'FINISHED'
                || $this->repository->findResult($raffleId) !== null
            ) {
                $this->pdo->commit();

                return 'skipped';
            }

            if (! $this->isDue($raffle)) {
                $this->pdo->commit();

                return 'skipped';
            }

            if (! $this->hasValidSchedule($raffle)) {
                $this->repository->markBlocked($raffleId, 'INVALID_SCHEDULE');
                $this->pdo->commit();

                return 'blocked';
            }

            $blocker = $this->findBlocker($raffleId);

            if ($blocker !== null) {
                $this->repository->markBlocked($raffleId, $blocker);
                $this->pdo->commit();

                return 'blocked';
            }

            $candidates = $this->repository->candidates($raffleId);

            if ($candidates === []) {
                $this->repository->markBlocked($raffleId, 'NO_SOLD_NUMBERS');
                $this->pdo->commit();

                return 'blocked';
            }

            $selectedIndex = random_int(0, count($candidates) - 1);
            $winner = $candidates[$selectedIndex];
            $candidateHash = $this->candidateHash($candidates);
            $executedAt = gmdate('Y-m-d H:i:s');
            $evidence = [
                'algorithm' => 'rifacloud_draw',
                'version' => self::DRAW_VERSION,
                'random_method' => self::RANDOM_METHOD,
                'candidate_count' => count($candidates),
                'candidate_hash' => $candidateHash,
                'selected_index' => $selectedIndex,
                'winning_number' => $winner['number'],
                'draw_at_utc' => (string) $raffle['draw_at'],
                'executed_at_utc' => $executedAt,
                'candidates' => array_map(
                    static fn (array $candidate): array => [
                        'raffle_number_id' => $candidate['raffle_number_id'],
                        'number' => $candidate['number'],
                        'order_id' => $candidate['order_id'],
                    ],
                    $candidates,
                ),
            ];

            $this->repository->insertResult([
                'raffle_id' => $raffleId,
                'winning_number' => $winner['number'],
                'winning_raffle_number_id' => $winner['raffle_number_id'],
                'winner_order_id' => $winner['order_id'],
                'winner_user_id' => $winner['user_id'],
                'public_winner_name' => PublicWinnerName::mask($winner['name']),
                'candidate_count' => count($candidates),
                'candidate_hash' => $candidateHash,
                'selected_index' => $selectedIndex,
                'random_method' => self::RANDOM_METHOD,
                'draw_evidence' => $evidence,
                'draw_version' => self::DRAW_VERSION,
                'evidence_description' => sprintf(
                    'Sorteo automático: ganador seleccionado entre %d números SOLD con pago APPROVED.',
                    count($candidates),
                ),
            ]);

            $this->repository->markFinished($raffleId);
            $this->pdo->commit();

            return 'drawn';
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // A concurrent draw that already inserted the unique result is a
            // successful no-op, not a new incident. Other integrity failures
            // remain errors and leave the raffle blocked for review.
            if (($exception->errorInfo[1] ?? null) === 1062) {
                return 'skipped';
            }

            $this->safeMarkBlocked($raffleId, 'DRAW_DATABASE_ERROR');

            return 'error';
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->safeMarkBlocked($raffleId, 'DRAW_ERROR');

            return 'error';
        }
    }

    /**
     * @param  array<string, mixed>  $raffle
     */
    private function isDue(array $raffle): bool
    {
        if ((string) $raffle['draw_mode'] !== 'AUTOMATIC' || $raffle['draw_at'] === null) {
            return false;
        }

        if (! in_array((string) $raffle['status'], ['PUBLISHED', 'CLOSED'], true)) {
            return false;
        }

        $now = time();
        $endsAt = strtotime((string) $raffle['ends_at']);
        $drawAt = strtotime((string) $raffle['draw_at']);

        return $endsAt !== false
            && $drawAt !== false
            && $endsAt <= $now
            && $drawAt <= $now;
    }

    /**
     * @param  array<string, mixed>  $raffle
     */
    private function hasValidSchedule(array $raffle): bool
    {
        if ($raffle['ends_at'] === null || $raffle['draw_at'] === null) {
            return false;
        }

        $endsAt = strtotime((string) $raffle['ends_at']);
        $drawAt = strtotime((string) $raffle['draw_at']);

        if ($endsAt === false || $drawAt === false) {
            return false;
        }

        return $drawAt >= $endsAt + ($this->bufferMinutes * 60);
    }

    private function findBlocker(int $raffleId): ?string
    {
        if ($this->repository->hasBlockingOrders($raffleId)) {
            return 'UNCERTAIN_ORDERS';
        }

        if ($this->repository->hasReservedNumbers($raffleId)) {
            return 'ACTIVE_RESERVATIONS';
        }

        if ($this->repository->hasUncertainPayments($raffleId)) {
            return 'UNCERTAIN_PAYMENTS';
        }

        if ($this->repository->hasInconsistentSoldNumbers($raffleId)) {
            return 'INCONSISTENT_SOLD_NUMBERS';
        }

        return null;
    }

    /**
     * @param  list<array{raffle_number_id: int, number: int, order_id: int, user_id: int, name: string}>  $candidates
     */
    private function candidateHash(array $candidates): string
    {
        $canonical = array_map(
            static fn (array $candidate): array => [
                'raffle_number_id' => $candidate['raffle_number_id'],
                'number' => $candidate['number'],
                'order_id' => $candidate['order_id'],
            ],
            $candidates,
        );

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    private function safeMarkBlocked(int $raffleId, string $reason): void
    {
        try {
            $this->repository->markBlocked($raffleId, $reason);
        } catch (Throwable) {
            // The reason is best-effort; the raffle remains unchanged and the
            // next scheduler run retries.
        }
    }
}
