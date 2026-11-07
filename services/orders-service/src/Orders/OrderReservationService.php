<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Orders;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/**
 * Port of App\Modules\Orders\Services\OrderReservationService.
 *
 * The order is created first so raffle_numbers.order_id can reference it, then
 * the requested rows are locked and reserved. Any failure rolls the whole
 * transaction back, including the order itself (FR-006, FR-007).
 *
 * Concurrency: the raffle row and the requested number rows are locked with
 * SELECT ... FOR UPDATE in a deterministic order (raffle, then numbers by
 * ascending number). A concurrent request for the same numbers therefore waits
 * for the first transaction and then observes RESERVED, returning 409 instead
 * of creating a second sale (NFR-002, NFR-004).
 */
final class OrderReservationService
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $reservationMinutes,
        private readonly int $maxNumbersPerOrder,
    ) {}

    /**
     * @param  list<int>  $numbers
     *
     * @throws ValidationException
     * @throws OrderConflictException
     */
    public function create(int $userId, int $raffleId, array $numbers): int
    {
        $numbers = $this->normalizeNumbers($numbers);
        $this->assertValidCount($numbers);

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->attemptCreate($userId, $raffleId, $numbers);
            } catch (ValidationException|OrderConflictException $exception) {
                $this->rollBack();

                throw $exception;
            } catch (PDOException $exception) {
                $this->rollBack();

                if ($attempt < self::MAX_ATTEMPTS && $this->isRetryable($exception)) {
                    usleep(random_int(20_000, 80_000) * $attempt);

                    continue;
                }

                throw $exception;
            } catch (Throwable $exception) {
                $this->rollBack();

                throw $exception;
            }
        }
    }

    /**
     * @param  list<int>  $numbers
     */
    private function attemptCreate(int $userId, int $raffleId, array $numbers): int
    {
        $this->pdo->beginTransaction();

        $raffleStatement = $this->pdo->prepare(
            <<<'SQL'
                SELECT id, title, price_per_number, min_number, max_number, status, starts_at, ends_at
                FROM raffles
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            SQL,
        );
        $raffleStatement->execute([':id' => $raffleId]);
        $raffle = $raffleStatement->fetch();

        if ($raffle === false || (string) $raffle['status'] !== 'PUBLISHED') {
            throw OrderConflictException::raffleNotAvailable();
        }

        $now = $this->utcNow();
        $this->assertPurchaseWindow($raffle, $now);
        $this->assertNumbersInRange($raffle, $numbers);

        $expiresAt = $now->add(new \DateInterval('PT'.$this->reservationMinutes.'M'));
        $price = (int) $raffle['price_per_number'];

        $orderNumber = $this->generateOrderNumber();

        $orderStatement = $this->pdo->prepare(
            <<<'SQL'
                INSERT INTO orders
                    (user_id, raffle_id, order_number, total_amount, status, expires_at, created_at, updated_at)
                VALUES
                    (:user_id, :raffle_id, :order_number, :total_amount, 'PENDING_PAYMENT', :expires_at, :created_at, :updated_at)
            SQL,
        );
        $orderStatement->execute([
            ':user_id' => $userId,
            ':raffle_id' => $raffleId,
            ':order_number' => $orderNumber,
            ':total_amount' => $price * count($numbers),
            ':expires_at' => $this->databaseDate($expiresAt),
            ':created_at' => $this->databaseDate($now),
            ':updated_at' => $this->databaseDate($now),
        ]);

        $orderId = (int) $this->pdo->lastInsertId();

        $placeholders = implode(', ', array_fill(0, count($numbers), '?'));

        $numbersStatement = $this->pdo->prepare(
            'SELECT id, number, status
             FROM raffle_numbers
             WHERE raffle_id = ? AND number IN ('.$placeholders.')
             ORDER BY number
             FOR UPDATE',
        );
        $numbersStatement->execute([$raffleId, ...$numbers]);
        $lockedNumbers = $numbersStatement->fetchAll();

        $this->assertNumbersBelongToRaffle($numbers, $lockedNumbers);
        $this->assertNumbersAvailable($lockedNumbers);

        $numberIds = array_map(
            static fn (array $number): int => (int) $number['id'],
            $lockedNumbers,
        );
        $idPlaceholders = implode(', ', array_fill(0, count($numberIds), '?'));

        $reserveStatement = $this->pdo->prepare(
            'UPDATE raffle_numbers
             SET status = \'RESERVED\',
                 reserved_at = ?,
                 reserved_until = ?,
                 order_id = ?,
                 updated_at = ?
             WHERE id IN ('.$idPlaceholders.")
               AND status = 'AVAILABLE'",
        );
        $reserveStatement->execute([
            $this->databaseDate($now),
            $this->databaseDate($expiresAt),
            $orderId,
            $this->databaseDate($now),
            ...$numberIds,
        ]);

        if ($reserveStatement->rowCount() !== count($numberIds)) {
            // Defensive check for a concurrent reservation that slipped past
            // the row locks (NFR-002, NFR-004).
            throw OrderConflictException::numbersUnavailable($numbers);
        }

        $itemsStatement = $this->pdo->prepare(
            'INSERT INTO order_items
                (order_id, raffle_number_id, price_at_purchase, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)',
        );

        foreach ($numberIds as $numberId) {
            $itemsStatement->execute([
                $orderId,
                $numberId,
                $price,
                $this->databaseDate($now),
                $this->databaseDate($now),
            ]);
        }

        $this->pdo->commit();

        return $orderId;
    }

    /**
     * @param  array<int, mixed>  $numbers
     * @return list<int>
     */
    private function normalizeNumbers(array $numbers): array
    {
        $normalized = array_values(array_unique(array_map(
            static fn (mixed $number): int => (int) $number,
            $numbers,
        )));

        sort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $raffle
     */
    private function assertPurchaseWindow(array $raffle, DateTimeImmutable $now): void
    {
        $startsAt = $this->parseDatabaseDate($raffle['starts_at'] ?? null);
        $endsAt = $this->parseDatabaseDate($raffle['ends_at'] ?? null);

        if ($startsAt !== null && $endsAt !== null && $startsAt >= $endsAt) {
            throw OrderConflictException::raffleScheduleInvalid();
        }

        if ($startsAt !== null && $startsAt > $now) {
            throw OrderConflictException::raffleNotStarted();
        }

        if ($endsAt !== null && $endsAt < $now) {
            throw OrderConflictException::raffleEnded();
        }
    }

    /**
     * @param  list<int>  $numbers
     */
    private function assertValidCount(array $numbers): void
    {
        if (count($numbers) < 1) {
            throw ValidationException::field('numbers', 'Debes seleccionar al menos un número.');
        }

        if (count($numbers) > $this->maxNumbersPerOrder) {
            throw ValidationException::field(
                'numbers',
                "No puedes seleccionar más de {$this->maxNumbersPerOrder} números por orden.",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $raffle
     * @param  list<int>  $numbers
     */
    private function assertNumbersInRange(array $raffle, array $numbers): void
    {
        $min = (int) $raffle['min_number'];
        $max = (int) $raffle['max_number'];

        foreach ($numbers as $number) {
            if ($number < $min || $number > $max) {
                throw ValidationException::field(
                    'numbers',
                    'Algunos números están fuera del rango de la rifa.',
                );
            }
        }
    }

    /**
     * @param  list<int>  $numbers
     * @param  list<array<string, mixed>>  $lockedNumbers
     */
    private function assertNumbersBelongToRaffle(array $numbers, array $lockedNumbers): void
    {
        $found = array_map(
            static fn (array $number): int => (int) $number['number'],
            $lockedNumbers,
        );
        $missing = array_values(array_diff($numbers, $found));

        if ($missing !== []) {
            throw ValidationException::field(
                'numbers',
                'Algunos números no pertenecen a esta rifa.',
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lockedNumbers
     */
    private function assertNumbersAvailable(array $lockedNumbers): void
    {
        $unavailable = [];

        foreach ($lockedNumbers as $number) {
            if ((string) $number['status'] !== 'AVAILABLE') {
                $unavailable[] = (int) $number['number'];
            }
        }

        if ($unavailable !== []) {
            throw OrderConflictException::numbersUnavailable($unavailable);
        }
    }

    private function generateOrderNumber(): string
    {
        $date = $this->utcNow()->format('Ymd');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = sprintf('RC-%s-%s', $date, strtoupper(bin2hex(random_bytes(3))));

            $statement = $this->pdo->prepare(
                'SELECT 1 FROM orders WHERE order_number = :order_number LIMIT 1',
            );
            $statement->execute([':order_number' => $candidate]);

            if ($statement->fetchColumn() === false) {
                return $candidate;
            }
        }

        return sprintf('RC-%s-%s', $date, strtoupper(bin2hex(random_bytes(10))));
    }

    private function isRetryable(PDOException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        // Deadlock, lock wait timeout or a duplicate order_number race.
        return $sqlState === '40001'
            || in_array($driverCode, [1205, 1213, 1062], true);
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function databaseDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function parseDatabaseDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
