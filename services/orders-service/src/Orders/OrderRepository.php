<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Orders;

use PDO;

/**
 * Read model for GET /api/orders/{id}.
 *
 * The service reads payments as a projection (payments-service remains their
 * owner) and never writes them.
 */
final class OrderRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Internal projection used by the payment-result command (no owner filter;
     * the command is authenticated service-to-service).
     *
     * @return array{order: array<string, mixed>, numbers: list<array<string, mixed>>, payments: list<array<string, mixed>>}|null
     */
    public function findById(int $orderId): ?array
    {
        $record = $this->findForUser($orderId, 0, false);

        return $record;
    }

    /**
     * @return array{order: array<string, mixed>, numbers: list<array<string, mixed>>, payments: list<array<string, mixed>>}|null
     */
    public function findForUser(int $orderId, int $userId, bool $enforceOwner = true): ?array
    {
        $sql = <<<'SQL'
                SELECT o.id, o.user_id, o.raffle_id, o.order_number, o.total_amount,
                       o.status, o.expires_at, o.paid_at, o.created_at,
                       r.title AS raffle_title
                FROM orders o
                INNER JOIN raffles r ON r.id = o.raffle_id
                WHERE o.id = :id
            SQL;

        if ($enforceOwner) {
            $sql .= ' AND o.user_id = :user_id';
        }

        $orderStatement = $this->pdo->prepare($sql.' LIMIT 1');
        $parameters = [':id' => $orderId];

        if ($enforceOwner) {
            $parameters[':user_id'] = $userId;
        }

        $orderStatement->execute($parameters);
        $order = $orderStatement->fetch();

        if ($order === false) {
            return null;
        }

        $numbersStatement = $this->pdo->prepare(
            <<<'SQL'
                SELECT n.id, n.number, n.status, oi.price_at_purchase
                FROM order_items oi
                INNER JOIN raffle_numbers n ON n.id = oi.raffle_number_id
                WHERE oi.order_id = :order_id
                ORDER BY n.number
            SQL,
        );
        $numbersStatement->execute([':order_id' => $orderId]);

        $paymentsStatement = $this->pdo->prepare(
            <<<'SQL'
                SELECT id, provider, status, amount, currency, checkout_url,
                       response_data, verified_at, created_at
                FROM payments
                WHERE order_id = :order_id
                ORDER BY id
            SQL,
        );
        $paymentsStatement->execute([':order_id' => $orderId]);

        return [
            'order' => $order,
            'numbers' => $numbersStatement->fetchAll(),
            'payments' => $paymentsStatement->fetchAll(),
        ];
    }

    public function countForUser(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = :user_id');
        $statement->execute([':user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    public function countAll(?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM orders';
        $parameters = [];

        if ($status !== null && $status !== '') {
            $sql .= ' WHERE status = :status';
            $parameters[':status'] = $status;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /**
     * Admin projection: every order, newest first, with its buyer and latest
     * payment state. It is a read model only; financial rules stay in the
     * payment-result command.
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(?string $status, int $limit, int $offset): array
    {
        $sql = <<<'SQL'
                SELECT o.id, o.user_id, o.raffle_id, o.order_number, o.total_amount,
                       o.status, o.expires_at, o.paid_at, o.created_at,
                       r.title AS raffle_title,
                       u.name AS customer_name, u.email AS customer_email,
                       (SELECT p.status FROM payments p
                        WHERE p.order_id = o.id ORDER BY p.id DESC LIMIT 1) AS latest_payment_status
                FROM orders o
                INNER JOIN raffles r ON r.id = o.raffle_id
                INNER JOIN users u ON u.id = o.user_id
            SQL;
        $parameters = [];

        if ($status !== null && $status !== '') {
            $sql .= ' WHERE o.status = :status';
            $parameters[':status'] = $status;
        }

        $sql .= ' ORDER BY o.id DESC LIMIT :limit OFFSET :offset';

        $statement = $this->pdo->prepare($sql);

        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }

        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Newest first, always scoped to the authenticated owner.
     *
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId, int $limit, int $offset): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT o.id, o.user_id, o.raffle_id, o.order_number, o.total_amount,
                       o.status, o.expires_at, o.paid_at, o.created_at,
                       r.title AS raffle_title
                FROM orders o
                INNER JOIN raffles r ON r.id = o.raffle_id
                WHERE o.user_id = :user_id
                ORDER BY o.id DESC
                LIMIT :limit OFFSET :offset
            SQL,
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function numbersForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT oi.order_id, n.id, n.number, n.status, oi.price_at_purchase
             FROM order_items oi
             INNER JOIN raffle_numbers n ON n.id = oi.raffle_number_id
             WHERE oi.order_id IN ('.$placeholders.')
             ORDER BY n.number',
        );
        $statement->execute($orderIds);

        $grouped = [];

        foreach ($statement->fetchAll() as $number) {
            $grouped[(int) $number['order_id']][] = $number;
        }

        return $grouped;
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function paymentsForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT order_id, id, provider, status, amount, currency, checkout_url,
                    response_data, verified_at, created_at
             FROM payments
             WHERE order_id IN ('.$placeholders.')
             ORDER BY id',
        );
        $statement->execute($orderIds);

        $grouped = [];

        foreach ($statement->fetchAll() as $payment) {
            $grouped[(int) $payment['order_id']][] = $payment;
        }

        return $grouped;
    }
}
