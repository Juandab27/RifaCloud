<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Proposals;

use PDO;

/**
 * Persistence for raffle proposals.
 *
 * Ownership (ADR-001): raffles-service writes `raffle_proposals`. It only
 * reads `users` (contact snapshot) and never writes users, sessions, raffles,
 * orders or payments.
 */
final class RaffleProposalRepository
{
    public const PAGE_SIZE = 15;

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param  array{
     *     user_id: int,
     *     title: string,
     *     description: string,
     *     contact_name: string,
     *     contact_email: string,
     *     contact_phone: string,
     *     privacy_version: string
     * }  $data
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            <<<'SQL'
                INSERT INTO raffle_proposals
                    (user_id, title, description, contact_name, contact_email,
                     contact_phone, privacy_version, privacy_accepted_at, status,
                     created_at, updated_at)
                VALUES
                    (:user_id, :title, :description, :contact_name, :contact_email,
                     :contact_phone, :privacy_version, :privacy_accepted_at, 'PENDING',
                     :created_at, :updated_at)
            SQL,
        );
        $statement->execute([
            ':user_id' => $data['user_id'],
            ':title' => $data['title'],
            ':description' => $data['description'],
            ':contact_name' => $data['contact_name'],
            ':contact_email' => $data['contact_email'],
            ':contact_phone' => $data['contact_phone'],
            ':privacy_version' => $data['privacy_version'],
            ':privacy_accepted_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            $this->selectColumns().' WHERE p.id = :id LIMIT 1',
        );
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function countSince(int $userId, string $since): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM raffle_proposals
             WHERE user_id = :user_id AND created_at >= :since',
        );
        $statement->execute([
            ':user_id' => $userId,
            ':since' => $since,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function lastCreatedAt(int $userId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT created_at FROM raffle_proposals
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT 1',
        );
        $statement->execute([':user_id' => $userId]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function paginate(int $page, ?string $status): array
    {
        $page = max(1, $page);
        $where = '';
        $parameters = [];

        if ($status !== null) {
            $where = ' WHERE p.status = :status';
            $parameters[':status'] = $status;
        }

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM raffle_proposals p'.$where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * self::PAGE_SIZE;

        $statement = $this->pdo->prepare(
            $this->selectColumns()
            .$where
            .' ORDER BY p.created_at DESC, p.id DESC LIMIT :limit OFFSET :offset',
        );

        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->bindValue(':limit', self::PAGE_SIZE, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'data' => array_map(
                static fn (array $row): array => ProposalPresenter::admin($row),
                $statement->fetchAll(),
            ),
            'total' => $total,
            'page' => $page,
            'per_page' => self::PAGE_SIZE,
            'last_page' => $lastPage,
        ];
    }

    /**
     * @return array{pending: int, approved: int, rejected: int}
     */
    public function metrics(): array
    {
        $row = $this->pdo->query(
            <<<'SQL'
                SELECT
                    COALESCE(SUM(status = 'PENDING'), 0) AS pending,
                    COALESCE(SUM(status = 'APPROVED'), 0) AS approved,
                    COALESCE(SUM(status = 'REJECTED'), 0) AS rejected
                FROM raffle_proposals
            SQL,
        )->fetch();

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'approved' => (int) ($row['approved'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
        ];
    }

    /**
     * Applies a decision exactly once. A concurrent decision loses the
     * `status = 'PENDING'` condition and returns false.
     */
    public function decide(
        int $id,
        string $status,
        int $adminId,
        ?string $note,
    ): bool {
        $now = gmdate('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            <<<'SQL'
                UPDATE raffle_proposals
                SET status = :status,
                    decision_note = :note,
                    decided_by = :decided_by,
                    decided_at = :decided_at,
                    updated_at = :updated_at
                WHERE id = :id AND status = 'PENDING'
            SQL,
        );
        $statement->execute([
            ':status' => $status,
            ':note' => $note,
            ':decided_by' => $adminId,
            ':decided_at' => $now,
            ':updated_at' => $now,
            ':id' => $id,
        ]);

        return $statement->rowCount() === 1;
    }

    private function selectColumns(): string
    {
        return <<<'SQL'
            SELECT
                p.id,
                p.user_id,
                p.title,
                p.description,
                p.contact_name,
                p.contact_email,
                p.contact_phone,
                p.privacy_version,
                p.privacy_accepted_at,
                p.status,
                p.decision_note,
                p.decided_by,
                p.decided_at,
                p.created_at,
                p.updated_at,
                du.name AS decided_by_name,
                du.email AS decided_by_email
            FROM raffle_proposals p
            LEFT JOIN users du ON du.id = p.decided_by
        SQL;
    }
}
