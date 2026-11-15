<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Draw;

use PDO;

/**
 * Write repository for the automatic draw.
 *
 * Ownership: writes only raffles and raffle_results. It reads raffle_numbers,
 * orders, payments and users through the approved read-only grants. It never
 * changes number, order or payment state.
 */
final class RaffleDrawRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function closeDueSales(int $limit): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            <<<'SQL'
                UPDATE raffles
                SET status = 'CLOSED', updated_at = :updated_at
                WHERE status = 'PUBLISHED'
                  AND draw_mode = 'AUTOMATIC'
                  AND ends_at IS NOT NULL
                  AND ends_at <= :now
                ORDER BY ends_at, id
                LIMIT :limit
            SQL,
        );
        $statement->bindValue(':updated_at', $now);
        $statement->bindValue(':now', $now);
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    /**
     * @return list<int>
     */
    public function findDueRaffleIds(int $limit): array
    {
        $now = gmdate('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT r.id
                FROM raffles r
                LEFT JOIN raffle_results rr ON rr.raffle_id = r.id
                WHERE r.draw_mode = 'AUTOMATIC'
                  AND r.draw_at IS NOT NULL
                  AND r.ends_at IS NOT NULL
                  AND r.ends_at <= :ends_now
                  AND r.draw_at <= :draw_now
                  AND r.status IN ('PUBLISHED', 'CLOSED')
                  AND rr.id IS NULL
                ORDER BY r.draw_at, r.id
                LIMIT :limit
            SQL,
        );
        $statement->bindValue(':ends_now', $now);
        $statement->bindValue(':draw_now', $now);
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $raffleId): ?array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT id, title, status, starts_at, ends_at, draw_mode, draw_at,
                       draw_blocked_reason, draw_attempted_at, draw_attempts,
                       min_number, max_number
                FROM raffles
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            SQL,
        );
        $statement->execute([':id' => $raffleId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findResult(int $raffleId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT raffle_id, winning_number, published_source, published_at
             FROM raffle_results
             WHERE raffle_id = :raffle_id
             LIMIT 1',
        );
        $statement->execute([':raffle_id' => $raffleId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function hasBlockingOrders(int $raffleId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM orders
             WHERE raffle_id = :raffle_id
               AND status IN ('PENDING_PAYMENT', 'PAYMENT_REVIEW', 'REQUIRES_REFUND')
             LIMIT 1",
        );
        $statement->execute([':raffle_id' => $raffleId]);

        return $statement->fetchColumn() !== false;
    }

    public function hasReservedNumbers(int $raffleId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM raffle_numbers
             WHERE raffle_id = :raffle_id AND status = 'RESERVED'
             LIMIT 1",
        );
        $statement->execute([':raffle_id' => $raffleId]);

        return $statement->fetchColumn() !== false;
    }

    public function hasUncertainPayments(int $raffleId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM payments p
             JOIN orders o ON o.id = p.order_id
             WHERE o.raffle_id = :raffle_id
               AND p.status IN ('PENDING', 'UNKNOWN', 'REFUND_REQUIRED')
             LIMIT 1",
        );
        $statement->execute([':raffle_id' => $raffleId]);

        return $statement->fetchColumn() !== false;
    }

    public function hasInconsistentSoldNumbers(int $raffleId): bool
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT 1
                FROM raffle_numbers n
                LEFT JOIN orders o ON o.id = n.order_id
                LEFT JOIN payments p ON p.order_id = o.id
                WHERE n.raffle_id = :raffle_id
                  AND n.status = 'SOLD'
                  AND (
                      o.id IS NULL
                      OR o.raffle_id <> n.raffle_id
                      OR o.status <> 'PAID'
                      OR p.id IS NULL
                      OR p.status <> 'APPROVED'
                      OR p.amount <> o.total_amount
                      OR p.currency <> 'COP'
                  )
                LIMIT 1
            SQL,
        );
        $statement->execute([':raffle_id' => $raffleId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return list<array{raffle_number_id: int, number: int, order_id: int, user_id: int, name: string}>
     */
    public function candidates(int $raffleId): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT n.id AS raffle_number_id,
                       n.number,
                       o.id AS order_id,
                       o.user_id,
                       u.name
                FROM raffle_numbers n
                JOIN orders o ON o.id = n.order_id AND o.raffle_id = n.raffle_id
                JOIN payments p ON p.order_id = o.id
                JOIN users u ON u.id = o.user_id
                WHERE n.raffle_id = :raffle_id
                  AND n.status = 'SOLD'
                  AND o.status = 'PAID'
                  AND p.status = 'APPROVED'
                  AND p.amount = o.total_amount
                  AND p.currency = 'COP'
                ORDER BY n.number
            SQL,
        );
        $statement->execute([':raffle_id' => $raffleId]);

        return array_map(
            static fn (array $row): array => [
                'raffle_number_id' => (int) $row['raffle_number_id'],
                'number' => (int) $row['number'],
                'order_id' => (int) $row['order_id'],
                'user_id' => (int) $row['user_id'],
                'name' => (string) $row['name'],
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function insertResult(array $data): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            <<<'SQL'
                INSERT INTO raffle_results
                    (raffle_id, winning_number, winning_raffle_number_id,
                     published_by, published_source, published_at,
                     winner_order_id, winner_user_id, public_winner_name,
                     candidate_count, candidate_hash, selected_index,
                     random_method, draw_evidence, draw_version,
                     evidence_description, evidence_url, created_at, updated_at)
                VALUES
                    (:raffle_id, :winning_number, :winning_raffle_number_id,
                     NULL, 'AUTOMATIC', :published_at,
                     :winner_order_id, :winner_user_id, :public_winner_name,
                     :candidate_count, :candidate_hash, :selected_index,
                     :random_method, :draw_evidence, :draw_version,
                     :evidence_description, NULL, :created_at, :updated_at)
            SQL,
        );
        $statement->execute([
            ':raffle_id' => (int) $data['raffle_id'],
            ':winning_number' => (int) $data['winning_number'],
            ':winning_raffle_number_id' => (int) $data['winning_raffle_number_id'],
            ':published_at' => $now,
            ':winner_order_id' => (int) $data['winner_order_id'],
            ':winner_user_id' => (int) $data['winner_user_id'],
            ':public_winner_name' => $data['public_winner_name'],
            ':candidate_count' => (int) $data['candidate_count'],
            ':candidate_hash' => (string) $data['candidate_hash'],
            ':selected_index' => (int) $data['selected_index'],
            ':random_method' => (string) $data['random_method'],
            ':draw_evidence' => json_encode($data['draw_evidence'], JSON_THROW_ON_ERROR),
            ':draw_version' => (string) $data['draw_version'],
            ':evidence_description' => (string) $data['evidence_description'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function markFinished(int $raffleId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            <<<'SQL'
                UPDATE raffles
                SET status = 'FINISHED',
                    draw_blocked_reason = NULL,
                    draw_attempted_at = :attempted_at,
                    draw_attempts = draw_attempts + 1,
                    updated_at = :updated_at
                WHERE id = :id
            SQL,
        );
        $statement->execute([
            ':attempted_at' => $now,
            ':updated_at' => $now,
            ':id' => $raffleId,
        ]);
    }

    public function markBlocked(int $raffleId, string $reason): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            <<<'SQL'
                UPDATE raffles
                SET status = 'CLOSED',
                    draw_blocked_reason = :reason,
                    draw_attempted_at = :attempted_at,
                    draw_attempts = draw_attempts + 1,
                    updated_at = :updated_at
                WHERE id = :id
            SQL,
        );
        $statement->execute([
            ':reason' => mb_substr($reason, 0, 100),
            ':attempted_at' => $now,
            ':updated_at' => $now,
            ':id' => $raffleId,
        ]);
    }
}
