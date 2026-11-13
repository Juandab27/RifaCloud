<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Read-only projection used to build the exact OrderResource contract React
 * already consumes (`data` wrapper, ISO-8601, COP amounts, simulated flags).
 *
 * The service reads `orders`, `raffle_numbers` and `order_items` (owned by
 * orders-service) only to render the response; it never writes them.
 */
final class OrderProjection
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $paymentGateway,
    ) {}

    /**
     * @return array{id: int, order_number: string, total_amount: int, raffle_title: string|null}|null
     */
    public function forGateway(int $orderId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.order_number, o.total_amount, r.title AS raffle_title
             FROM orders o INNER JOIN raffles r ON r.id = o.raffle_id
             WHERE o.id = :id LIMIT 1',
        );
        $statement->execute([':id' => $orderId]);
        $order = $statement->fetch();

        if ($order === false) {
            return null;
        }

        return [
            'id' => (int) $order['id'],
            'order_number' => (string) $order['order_number'],
            'total_amount' => (int) $order['total_amount'],
            'raffle_title' => $order['raffle_title'] === null ? null : (string) $order['raffle_title'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function present(int $orderId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.raffle_id, o.order_number, o.total_amount, o.status,
                    o.expires_at, o.paid_at, o.created_at, r.title AS raffle_title
             FROM orders o INNER JOIN raffles r ON r.id = o.raffle_id
             WHERE o.id = :id LIMIT 1',
        );
        $statement->execute([':id' => $orderId]);
        $order = $statement->fetch();

        if ($order === false) {
            return null;
        }

        $numbersStatement = $this->pdo->prepare(
            'SELECT n.id, n.number, n.status, oi.price_at_purchase
             FROM order_items oi INNER JOIN raffle_numbers n ON n.id = oi.raffle_number_id
             WHERE oi.order_id = :order_id ORDER BY n.number',
        );
        $numbersStatement->execute([':order_id' => $orderId]);

        $paymentsStatement = $this->pdo->prepare(
            'SELECT id, provider, status, amount, currency, checkout_url,
                    response_data, verified_at, created_at
             FROM payments WHERE order_id = :order_id ORDER BY id',
        );
        $paymentsStatement->execute([':order_id' => $orderId]);

        return [
            'data' => [
                'id' => (int) $order['id'],
                'order_number' => (string) $order['order_number'],
                'status' => (string) $order['status'],
                'total_amount' => (int) $order['total_amount'],
                'expires_at' => $this->iso($order['expires_at']),
                'paid_at' => $this->iso($order['paid_at']),
                'created_at' => $this->iso($order['created_at']),
                'simulated_payments' => $this->paymentGateway === 'fake',
                'raffle' => [
                    'id' => (int) $order['raffle_id'],
                    'title' => (string) $order['raffle_title'],
                ],
                'numbers' => array_map(
                    static fn (array $number): array => [
                        'id' => (int) $number['id'],
                        'number' => (int) $number['number'],
                        'status' => (string) $number['status'],
                        'price_at_purchase' => (int) $number['price_at_purchase'],
                    ],
                    $numbersStatement->fetchAll(),
                ),
                'payments' => array_map(
                    fn (array $payment): array => [
                        'id' => (int) $payment['id'],
                        'provider' => (string) $payment['provider'],
                        'status' => (string) $payment['status'],
                        'amount' => (int) $payment['amount'],
                        'currency' => (string) $payment['currency'],
                        'checkout_url' => $payment['checkout_url'] === null
                            ? null
                            : (string) $payment['checkout_url'],
                        'simulated' => $this->paymentIsSimulated($payment),
                        'verified_at' => $this->iso($payment['verified_at']),
                        'created_at' => $this->iso($payment['created_at']),
                    ],
                    $paymentsStatement->fetchAll(),
                ),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function paymentIsSimulated(array $payment): bool
    {
        $response = $payment['response_data'] ?? null;

        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);

            if (is_array($decoded) && array_key_exists('simulated', $decoded)) {
                return (bool) $decoded['simulated'];
            }
        }

        return $this->paymentGateway === 'fake';
    }

    private function iso(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('c');
    }
}
