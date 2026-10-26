<?php

namespace App\Modules\Orders\Services;

use App\Modules\Numbers\Enums\NumberStatus;
use App\Modules\Numbers\Models\RaffleNumber;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReservationExpiryService
{
    public const CHUNK_SIZE = 100;

    /**
     * Expire PENDING_PAYMENT orders whose reservation has elapsed and release
     * their numbers (FR-007, AC-012).
     *
     * Orders in PAYMENT_REVIEW, REQUIRES_REFUND or PAID are never candidates,
     * and every transaction re-checks the status and any payment result under
     * row locks. A payment in UNKNOWN, APPROVED or REFUND_REQUIRED always
     * blocks release (07-resilience-and-failure-handling.md §2.8).
     */
    public function expire(int $chunkSize = self::CHUNK_SIZE): int
    {
        $now = now();
        $expired = 0;

        Order::query()
            ->where('status', OrderStatus::PENDING_PAYMENT->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->chunkById(max(1, $chunkSize), function ($orders) use ($now, &$expired): void {
                foreach ($orders as $order) {
                    if ($this->expireOrder($order->id, $now)) {
                        $expired++;
                    }
                }
            });

        return $expired;
    }

    private function expireOrder(int $orderId, \DateTimeInterface $now): bool
    {
        return DB::transaction(function () use ($orderId, $now): bool {
            $order = Order::query()
                ->whereKey($orderId)
                ->lockForUpdate()
                ->first();

            if ($order === null || $order->status !== OrderStatus::PENDING_PAYMENT) {
                return false;
            }

            if ($order->status->blocksReservationRelease()) {
                return false;
            }

            if ($order->expires_at === null || $order->expires_at->greaterThan($now)) {
                return false;
            }

            // Lock payments before numbers to match PaymentService's lock order
            // (order -> payment -> numbers) and avoid deadlocks.
            $payments = $order->payments()->lockForUpdate()->get();

            if ($payments->contains(
                static fn (Payment $payment): bool => $payment->status->blocksReservationRelease(),
            )) {
                Log::warning('Skipping reservation expiry: payment status requires review.', [
                    'order_id' => $order->id,
                ]);

                return false;
            }

            $numbers = RaffleNumber::query()
                ->where('order_id', $order->id)
                ->orderBy('number')
                ->lockForUpdate()
                ->get();

            if ($numbers->contains(static fn (RaffleNumber $number): bool => $number->status === NumberStatus::SOLD)) {
                // Never release an order that already owns sold numbers.
                Log::warning('Skipping reservation expiry: order has sold numbers.', [
                    'order_id' => $order->id,
                ]);

                return false;
            }

            $numberIds = $numbers->modelKeys();

            if ($numberIds !== []) {
                RaffleNumber::query()
                    ->whereKey($numberIds)
                    ->where('order_id', $order->id)
                    ->where('status', NumberStatus::RESERVED->value)
                    ->update([
                        'status' => NumberStatus::AVAILABLE->value,
                        'reserved_at' => null,
                        'reserved_until' => null,
                        'order_id' => null,
                        'updated_at' => now(),
                    ]);
            }

            $order->forceFill(['status' => OrderStatus::EXPIRED])->save();

            return true;
        });
    }
}
