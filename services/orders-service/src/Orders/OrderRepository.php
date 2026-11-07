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
}
