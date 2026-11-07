<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Orders;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Exact port of App\Modules\Orders\Http\Resources\OrderResource.
 *
 * The JSON shape is part of the React contract: `data` wrapper, ISO-8601
 * timestamps, prices in cents and the `simulated_payments` / `simulated`
 * flags derived from PAYMENT_GATEWAY. The collection variant keeps the same
 * order object and adds a Laravel-style `meta` block.
 */
final class OrderPresenter
{
    public function __construct(private readonly string $paymentGateway) {}

    /**
     * @param  array{order: array<string, mixed>, numbers: list<array<string, mixed>>, payments: list<array<string, mixed>>}  $record
     * @return array<string, mixed>
     */
    public function present(array $record): array
    {
        return [
            'data' => $this->presentData($record['order'], $record['numbers'], $record['payments']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @param  array<int, list<array<string, mixed>>>  $numbersByOrder
     * @param  array<int, list<array<string, mixed>>>  $paymentsByOrder
     * @return array<string, mixed>
     */
    public function presentCollection(
        array $orders,
        array $numbersByOrder,
        array $paymentsByOrder,
        int $page,
        int $perPage,
        int $total,
    ): array {
        $data = [];

        foreach ($orders as $order) {
            $orderId = (int) $order['id'];

            $data[] = $this->presentData(
                $order,
                $numbersByOrder[$orderId] ?? [],
                $paymentsByOrder[$orderId] ?? [],
            );
        }

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @param  array<int, list<array<string, mixed>>>  $numbersByOrder
     * @param  array<int, list<array<string, mixed>>>  $paymentsByOrder
     * @return array<string, mixed>
     */
    public function presentAdminCollection(
        array $orders,
        array $numbersByOrder,
        array $paymentsByOrder,
        int $page,
        int $perPage,
        int $total,
    ): array {
        $data = [];

        foreach ($orders as $order) {
            $orderId = (int) $order['id'];
            $entry = $this->presentData(
                $order,
                $numbersByOrder[$orderId] ?? [],
                $paymentsByOrder[$orderId] ?? [],
            );
            $entry['customer'] = [
                'id' => (int) $order['user_id'],
                'name' => (string) ($order['customer_name'] ?? ''),
                'email' => (string) ($order['customer_email'] ?? ''),
            ];
            $entry['latest_payment_status'] = $order['latest_payment_status'] === null
                ? null
                : (string) $order['latest_payment_status'];
            $data[] = $entry;
        }

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  list<array<string, mixed>>  $numbers
     * @param  list<array<string, mixed>>  $payments
     * @return array<string, mixed>
     */
    private function presentData(array $order, array $numbers, array $payments): array
    {
        return [
            'id' => (int) $order['id'],
            'order_number' => (string) $order['order_number'],
            'status' => (string) $order['status'],
            'total_amount' => (int) $order['total_amount'],
            'expires_at' => $this->iso($order['expires_at'] ?? null),
            'paid_at' => $this->iso($order['paid_at'] ?? null),
            'created_at' => $this->iso($order['created_at'] ?? null),
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
                $numbers,
            ),
            'payments' => array_map(
                fn (array $payment): array => [
                    'id' => (int) $payment['id'],
                    'provider' => (string) $payment['provider'],
                    'status' => (string) $payment['status'],
                    'amount' => (int) $payment['amount'],
                    'currency' => (string) $payment['currency'],
                    'checkout_url' => $payment['checkout_url'] !== null
                        ? (string) $payment['checkout_url']
                        : null,
                    'simulated' => $this->paymentIsSimulated($payment),
                    'verified_at' => $this->iso($payment['verified_at'] ?? null),
                    'created_at' => $this->iso($payment['created_at'] ?? null),
                ],
                $payments,
            ),
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
