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
 * flags derived from PAYMENT_GATEWAY.
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
        $order = $record['order'];

        return [
            'data' => [
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
                    $record['numbers'],
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
                    $record['payments'],
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
