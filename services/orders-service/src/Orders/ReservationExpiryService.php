<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Orders;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Port of App\Modules\Orders\Services\ReservationExpiryService.
 *
 * Expire PENDING_PAYMENT orders whose reservation elapsed and release their
 * numbers (FR-007, AC-012). Orders in PAYMENT_REVIEW, REQUIRES_REFUND or PAID
 * are never candidates, and every transaction re-checks the status and any
 * payment result under row locks. A payment in UNKNOWN, APPROVED or
 * REFUND_REQUIRED always blocks release
 * (07-resilience-and-failure-handling.md §2.8).
 *
 * Double execution: the scheduler command in the monolith calls an internal
 * endpoint rather than running this logic, and this service serializes every
 * run with a MySQL advisory lock (GET_LOCK). Two schedulers, two containers or
 * an overlapping manual run cannot release the same reservation concurrently.
 */
final class ReservationExpiryService
{
    public const CHUNK_SIZE = 100;

    /** @var list<string> */
    private const BLOCKING_PAYMENT_STATES = ['UNKNOWN', 'APPROVED', 'REFUND_REQUIRED'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $lockName,
    ) {}

    /**
     * @return array{expired: int, skipped: bool}
     */
    public function expireWithLock(int $chunkSize = self::CHUNK_SIZE): array
    {
        if (! $this->acquireLock()) {
            return ['expired' => 0, 'skipped' => true];
        }

        try {
            return ['expired' => $this->expire($chunkSize), 'skipped' => false];
        } finally {
            $this->releaseLock();
        }
    }

    private function expire(int $chunkSize): int
    {
        $now = $this->utcNow()->format('Y-m-d H:i:s');
        $expired = 0;
        $lastId = 0;
        $chunkSize = max(1, $chunkSize);

        while (true) {
            $statement = $this->pdo->prepare(
                <<<'SQL'
                    SELECT id
                    FROM orders
                    WHERE status = 'PENDING_PAYMENT'
                      AND expires_at IS NOT NULL
                      AND expires_at <= :now
                      AND id > :last_id
                    ORDER BY id
                    LIMIT :chunk_size
                SQL,
            );
            $statement->bindValue(':now', $now);
            $statement->bindValue(':last_id', $lastId, PDO::PARAM_INT);
            $statement->bindValue(':chunk_size', $chunkSize, PDO::PARAM_INT);
            $statement->execute();
            $ids = $statement->fetchAll(PDO::FETCH_COLUMN);

            if ($ids === []) {
                break;
            }

            foreach ($ids as $id) {
                $lastId = (int) $id;

                if ($this->expireOrder($lastId, $now)) {
                    $expired++;
                }
            }

            if (count($ids) < $chunkSize) {
                break;
            }
        }

        return $expired;
    }

    private function expireOrder(int $orderId, string $now): bool
    {
        $this->pdo->beginTransaction();

        try {
            $orderStatement = $this->pdo->prepare(
                'SELECT id, status, expires_at FROM orders WHERE id = :id LIMIT 1 FOR UPDATE',
            );
            $orderStatement->execute([':id' => $orderId]);
            $order = $orderStatement->fetch();

            if ($order === false || (string) $order['status'] !== 'PENDING_PAYMENT') {
                $this->pdo->commit();

                return false;
            }

            if ($order['expires_at'] === null || (string) $order['expires_at'] > $now) {
                $this->pdo->commit();

                return false;
            }

            // Lock payments before numbers to match the payment flow's lock
            // order (order -> payment -> numbers) and avoid deadlocks.
            $paymentsStatement = $this->pdo->prepare(
                'SELECT id, status FROM payments WHERE order_id = :order_id ORDER BY id FOR UPDATE',
            );
            $paymentsStatement->execute([':order_id' => $orderId]);

            foreach ($paymentsStatement->fetchAll() as $payment) {
                if (in_array((string) $payment['status'], self::BLOCKING_PAYMENT_STATES, true)) {
                    $this->logSkip($orderId, 'payment status requires review');

                    $this->pdo->commit();

                    return false;
                }
            }

            $numbersStatement = $this->pdo->prepare(
                'SELECT id, status FROM raffle_numbers WHERE order_id = :order_id ORDER BY number FOR UPDATE',
            );
            $numbersStatement->execute([':order_id' => $orderId]);
            $numbers = $numbersStatement->fetchAll();

            foreach ($numbers as $number) {
                if ((string) $number['status'] === 'SOLD') {
                    // Never release an order that already owns sold numbers.
                    $this->logSkip($orderId, 'order has sold numbers');

                    $this->pdo->commit();

                    return false;
                }
            }

            $releaseStatement = $this->pdo->prepare(
                <<<'SQL'
                    UPDATE raffle_numbers
                    SET status = 'AVAILABLE',
                        reserved_at = NULL,
                        reserved_until = NULL,
                        order_id = NULL,
                        updated_at = :updated_at
                    WHERE order_id = :order_id
                      AND status = 'RESERVED'
                SQL,
            );
            $releaseStatement->execute([
                ':updated_at' => $now,
                ':order_id' => $orderId,
            ]);

            $expireStatement = $this->pdo->prepare(
                "UPDATE orders SET status = 'EXPIRED', updated_at = :updated_at WHERE id = :id",
            );
            $expireStatement->execute([
                ':updated_at' => $now,
                ':id' => $orderId,
            ]);

            $this->pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function acquireLock(): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $statement->execute([':name' => $this->lockName]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function releaseLock(): void
    {
        try {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $statement->execute([':name' => $this->lockName]);
        } catch (Throwable) {
            // The connection may already be gone; MySQL releases the lock when
            // the session ends, so the next run can proceed.
        }
    }

    private function logSkip(int $orderId, string $reason): void
    {
        error_log(sprintf(
            "[orders-service] skipping reservation expiry for order %d: %s\n",
            $orderId,
            $reason,
        ));
    }

    private function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
