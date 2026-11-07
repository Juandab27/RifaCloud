<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService;

use PDO;

/**
 * Read-only projection of the raffles and raffle_numbers tables.
 *
 * This is intentionally a read model, not a second source of truth. Raffle
 * writes, number generation, reservations, orders and payments still belong
 * to the Laravel monolith until the transactional split is designed and
 * approved.
 */
final class RaffleRepository
{
    public const CATALOG_PAGE_SIZE = 15;

    public const NUMBERS_MAX_PAGE_SIZE = 200;

    /** @var bool|null */
    private ?bool $raffleResultsAvailable = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $reservationMinutes,
        private readonly int $maxNumbersPerOrder,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function paginatePublished(int $page): array
    {
        $page = max(1, $page);
        $perPage = self::CATALOG_PAGE_SIZE;

        $total = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM raffles WHERE status IN ('PUBLISHED', 'FINISHED')")
            ->fetchColumn();

        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT
                    r.id,
                    r.title,
                    r.description,
                    r.price_per_number,
                    r.min_number,
                    r.max_number,
                    r.status,
                    r.starts_at,
                    r.ends_at,
                    r.created_at,
                    r.updated_at,
                    (SELECT COUNT(*) FROM raffle_numbers rn WHERE rn.raffle_id = r.id) AS numbers_count
                FROM raffles r
                WHERE r.status IN ('PUBLISHED', 'FINISHED')
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT :limit OFFSET :offset
            SQL,
        );
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'data' => $this->withResults($statement->fetchAll()),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublished(int $raffleId): ?array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT
                    r.id,
                    r.title,
                    r.description,
                    r.price_per_number,
                    r.min_number,
                    r.max_number,
                    r.status,
                    r.starts_at,
                    r.ends_at,
                    r.created_at,
                    r.updated_at,
                    (SELECT COUNT(*) FROM raffle_numbers rn WHERE rn.raffle_id = r.id) AS numbers_count
                FROM raffles r
                WHERE r.id = :raffle_id AND r.status IN ('PUBLISHED', 'FINISHED')
                LIMIT 1
            SQL,
        );
        $statement->execute([':raffle_id' => $raffleId]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->withResults([$row])[0];
    }

    /**
     * @return array{
     *     data: list<array{id: int, number: int, status: string}>,
     *     meta: array{current_page: int, last_page: int, per_page: int, total: int, counts: array{available: int, reserved: int, sold: int}}
     * }|null
     */
    public function numbersForPublishedRaffle(int $raffleId, int $page, int $perPage): ?array
    {
        $exists = $this->pdo->prepare(
            "SELECT 1 FROM raffles WHERE id = :raffle_id AND status IN ('PUBLISHED', 'FINISHED') LIMIT 1",
        );
        $exists->execute([':raffle_id' => $raffleId]);

        if ($exists->fetchColumn() === false) {
            return null;
        }

        $page = max(1, $page);
        $perPage = min(max(1, $perPage), self::NUMBERS_MAX_PAGE_SIZE);

        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM raffle_numbers WHERE raffle_id = :raffle_id',
        );
        $count->execute([':raffle_id' => $raffleId]);
        $total = (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $numbers = $this->pdo->prepare(
            <<<'SQL'
                SELECT id, number, status
                FROM raffle_numbers
                WHERE raffle_id = :raffle_id
                ORDER BY number
                LIMIT :limit OFFSET :offset
            SQL,
        );
        $numbers->bindValue(':raffle_id', $raffleId, PDO::PARAM_INT);
        $numbers->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $numbers->bindValue(':offset', $offset, PDO::PARAM_INT);
        $numbers->execute();

        $data = array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'number' => (int) $row['number'],
                'status' => (string) $row['status'],
            ],
            $numbers->fetchAll(),
        );

        $statusCounts = $this->pdo->prepare(
            <<<'SQL'
                SELECT status, COUNT(*) AS aggregate
                FROM raffle_numbers
                WHERE raffle_id = :raffle_id
                GROUP BY status
            SQL,
        );
        $statusCounts->execute([':raffle_id' => $raffleId]);

        $counts = ['AVAILABLE' => 0, 'RESERVED' => 0, 'SOLD' => 0];

        foreach ($statusCounts->fetchAll() as $row) {
            $status = (string) $row['status'];

            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['aggregate'];
            }
        }

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'counts' => [
                    'available' => $counts['AVAILABLE'],
                    'reserved' => $counts['RESERVED'],
                    'sold' => $counts['SOLD'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $result
     * @return array<string, mixed>
     */
    private function presentRaffle(array $row, ?array $result = null): array
    {
        return RafflePresenter::present(
            $row,
            $this->reservationMinutes,
            $this->maxNumbersPerOrder,
            $result,
        );
    }

    /**
     * The migration that creates raffle_results can be pending on a
     * transitional database. The public read model stays available and simply
     * omits the result until the table exists.
     */
    private function raffleResultsAvailable(): bool
    {
        if ($this->raffleResultsAvailable === null) {
            $this->raffleResultsAvailable =
                $this->pdo->query("SHOW TABLES LIKE 'raffle_results'")->fetchColumn() !== false;
        }

        return $this->raffleResultsAvailable;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withResults(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        if (! $this->raffleResultsAvailable()) {
            return array_map(fn (array $row): array => $this->presentRaffle($row), $rows);
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            'SELECT raffle_id, winning_number, published_at, evidence_description, evidence_url
             FROM raffle_results
             WHERE raffle_id IN ('.$placeholders.')',
        );
        $statement->execute($ids);

        $results = [];

        foreach ($statement->fetchAll() as $result) {
            $results[(int) $result['raffle_id']] = $result;
        }

        return array_map(
            fn (array $row): array => $this->presentRaffle(
                $row,
                $results[(int) $row['id']] ?? null,
            ),
            $rows,
        );
    }
}
