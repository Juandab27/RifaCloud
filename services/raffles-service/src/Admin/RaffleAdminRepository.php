<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Admin;

use PDO;
use RifaCloud\RafflesService\RafflePresenter;

/**
 * Write repository for the raffles domain (S2).
 *
 * Ownership boundary (ADR-001): this repository only touches `raffles` and
 * AVAILABLE rows in `raffle_numbers`. Reservations, orders, sold numbers and
 * payments stay in their owning services. The range-change guard refuses to
 * delete any RESERVED/SOLD row, so a raffle edit can never invalidate a
 * reservation or a sale.
 */
final class RaffleAdminRepository
{
    public const PAGE_SIZE = 15;

    public const NUMBER_CHUNK_SIZE = 1000;

    /** @var bool|null */
    private ?bool $raffleResultsAvailable = null;

    private const FIELDS = [
        'title',
        'description',
        'price_per_number',
        'min_number',
        'max_number',
        'starts_at',
        'ends_at',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $reservationMinutes,
        private readonly int $maxNumbersPerOrder,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function paginate(int $page): array
    {
        $page = max(1, $page);
        $perPage = self::PAGE_SIZE;

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM raffles')->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $statement = $this->pdo->prepare(
            $this->selectColumns().' ORDER BY r.created_at DESC, r.id DESC LIMIT :limit OFFSET :offset',
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
    public function find(int $raffleId): ?array
    {
        $statement = $this->pdo->prepare($this->selectColumns().' WHERE r.id = :id LIMIT 1');
        $statement->execute([':id' => $raffleId]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->withResults([$row])[0];
    }

    /**
     * Row used inside a transaction to serialize state checks and writes.
     *
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $raffleId): ?array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT id, title, description, price_per_number, min_number, max_number,
                       status, starts_at, ends_at, created_at, updated_at
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
     * @param  array<string, mixed>  $data
     */
    public function insert(array $data, int $adminId): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            <<<'SQL'
                INSERT INTO raffles
                    (title, description, price_per_number, min_number, max_number,
                     status, starts_at, ends_at, created_by, created_at, updated_at)
                VALUES
                    (:title, :description, :price_per_number, :min_number, :max_number,
                     'DRAFT', :starts_at, :ends_at, :created_by, :created_at, :updated_at)
            SQL,
        );

        $statement->execute([
            ':title' => (string) $data['title'],
            ':description' => $data['description'] ?? null,
            ':price_per_number' => (int) $data['price_per_number'],
            ':min_number' => (int) $data['min_number'],
            ':max_number' => (int) $data['max_number'],
            ':starts_at' => $data['starts_at'] ?? null,
            ':ends_at' => $data['ends_at'] ?? null,
            ':created_by' => $adminId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $raffleId, array $data): void
    {
        $sets = [];
        $parameters = [':id' => $raffleId, ':updated_at' => gmdate('Y-m-d H:i:s')];

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $sets[] = "{$field} = :{$field}";
            $parameters[":{$field}"] = $data[$field];
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updated_at = :updated_at';

        $statement = $this->pdo->prepare(
            'UPDATE raffles SET '.implode(', ', $sets).' WHERE id = :id',
        );
        $statement->execute($parameters);
    }

    public function updateStatus(int $raffleId, string $status): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE raffles SET status = :status, updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute([
            ':status' => $status,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':id' => $raffleId,
        ]);
    }

    public function raffleResultsAvailable(): bool
    {
        if ($this->raffleResultsAvailable === null) {
            $this->raffleResultsAvailable =
                $this->pdo->query("SHOW TABLES LIKE 'raffle_results'")->fetchColumn() !== false;
        }

        return $this->raffleResultsAvailable;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findResult(int $raffleId): ?array
    {
        if (! $this->raffleResultsAvailable()) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT raffle_id, winning_number, published_at, evidence_description, evidence_url
             FROM raffle_results
             WHERE raffle_id = :raffle_id
             LIMIT 1',
        );
        $statement->execute([':raffle_id' => $raffleId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The result can only reference a number that orders-service marked SOLD.
     */
    public function findSoldNumber(int $raffleId, int $number): ?int
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM raffle_numbers
             WHERE raffle_id = :raffle_id AND number = :number AND status = 'SOLD'
             LIMIT 1",
        );
        $statement->execute([
            ':raffle_id' => $raffleId,
            ':number' => $number,
        ]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function insertResult(
        int $raffleId,
        int $winningNumber,
        int $winningRaffleNumberId,
        int $publishedBy,
        string $evidenceDescription,
        ?string $evidenceUrl,
    ): void {
        $now = gmdate('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            <<<'SQL'
                INSERT INTO raffle_results
                    (raffle_id, winning_number, winning_raffle_number_id,
                     published_by, published_at, evidence_description, evidence_url,
                     created_at, updated_at)
                VALUES
                    (:raffle_id, :winning_number, :winning_raffle_number_id,
                     :published_by, :published_at, :evidence_description, :evidence_url,
                     :created_at, :updated_at)
            SQL,
        );
        $statement->execute([
            ':raffle_id' => $raffleId,
            ':winning_number' => $winningNumber,
            ':winning_raffle_number_id' => $winningRaffleNumberId,
            ':published_by' => $publishedBy,
            ':published_at' => $now,
            ':evidence_description' => $evidenceDescription,
            ':evidence_url' => $evidenceUrl,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function delete(int $raffleId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM raffles WHERE id = :id');
        $statement->execute([':id' => $raffleId]);
    }

    public function hasAssignedNumbers(int $raffleId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM raffle_numbers WHERE raffle_id = :id AND status <> 'AVAILABLE' LIMIT 1",
        );
        $statement->execute([':id' => $raffleId]);

        return $statement->fetchColumn() !== false;
    }

    public function deleteNumbers(int $raffleId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM raffle_numbers WHERE raffle_id = :id');
        $statement->execute([':id' => $raffleId]);
    }

    /**
     * Port of App\Modules\Numbers\Services\NumberGenerator::generate().
     *
     * Inserting in chunks keeps memory bounded for large ranges. Uniqueness is
     * enforced by the (raffle_id, number) unique index; this method must run
     * inside the transaction that creates/regenerates the raffle.
     */
    public function generateNumbers(int $raffleId, int $minNumber, int $maxNumber): int
    {
        $total = $maxNumber - $minNumber + 1;

        if ($total < 1) {
            return 0;
        }

        $now = gmdate('Y-m-d H:i:s');

        for ($offset = 0; $offset < $total; $offset += self::NUMBER_CHUNK_SIZE) {
            $chunkSize = min(self::NUMBER_CHUNK_SIZE, $total - $offset);
            $placeholders = implode(
                ',',
                array_fill(0, $chunkSize, '(?, ?, ?, ?, ?)'),
            );
            $parameters = [];

            for ($index = 0; $index < $chunkSize; $index++) {
                $parameters[] = $raffleId;
                $parameters[] = $minNumber + $offset + $index;
                $parameters[] = 'AVAILABLE';
                $parameters[] = $now;
                $parameters[] = $now;
            }

            $statement = $this->pdo->prepare(
                'INSERT INTO raffle_numbers (raffle_id, number, status, created_at, updated_at) VALUES '.$placeholders,
            );
            $statement->execute($parameters);
        }

        return $total;
    }

    private function selectColumns(): string
    {
        return <<<'SQL'
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
        SQL;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $result
     * @return array<string, mixed>
     */
    private function present(array $row, ?array $result = null): array
    {
        return RafflePresenter::present(
            $row,
            $this->reservationMinutes,
            $this->maxNumbersPerOrder,
            $result,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withResults(array $rows): array
    {
        if ($rows === [] || ! $this->raffleResultsAvailable()) {
            return array_map(fn (array $row): array => $this->present($row), $rows);
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
            fn (array $row): array => $this->present(
                $row,
                $results[(int) $row['id']] ?? null,
            ),
            $rows,
        );
    }
}
