<?php

namespace App\Modules\Payments\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Enums\NumberStatus;
use App\Modules\Numbers\Models\RaffleNumber;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\DTOs\PaymentStatus;
use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Exceptions\PaymentConflictException;
use App\Modules\Payments\Exceptions\PaymentGatewayTimeoutException;
use App\Modules\Payments\Models\Payment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Create the payment/checkout for an order at most once.
     *
     * A PENDING payment is resumed idempotently. If the previous result is
     * UNKNOWN, or the payment is already final, no new checkout is created:
     * a new charge must never be attempted blindly (NFR-001, AC-018).
     */
    public function createCheckout(User $user, Order $order): Payment
    {
        return DB::transaction(function () use ($user, $order): Payment {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOwner($user, $lockedOrder);

            $payments = Payment::query()
                ->where('order_id', $lockedOrder->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $blocked = $payments->first(
                fn (Payment $payment): bool => $payment->status->blocksNewCheckout(),
            );

            if ($blocked !== null) {
                if ($blocked->status === PaymentState::UNKNOWN) {
                    throw PaymentConflictException::paymentUncertain();
                }

                throw PaymentConflictException::paymentAlreadyFinal($blocked->status);
            }

            if ($lockedOrder->status !== OrderStatus::PENDING_PAYMENT) {
                throw PaymentConflictException::orderNotPayable();
            }

            if ($lockedOrder->expires_at !== null && ! $lockedOrder->expires_at->isFuture()) {
                throw PaymentConflictException::orderExpired();
            }

            $pending = $payments->first(
                fn (Payment $payment): bool => $payment->status === PaymentState::PENDING,
            );

            if ($pending !== null) {
                return $pending;
            }

            $session = $this->gateway->createCheckout($lockedOrder);

            return Payment::query()->create([
                'order_id' => $lockedOrder->id,
                'provider' => PaymentProvider::MERCADOPAGO,
                'external_id' => $session->externalId,
                'status' => PaymentState::PENDING,
                'amount' => $lockedOrder->total_amount,
                'currency' => 'COP',
                'checkout_url' => $session->checkoutUrl,
                'response_data' => [
                    'simulated' => config('rifacloud.payment_gateway') === 'fake',
                ],
            ]);
        }, 3);
    }

    /**
     * Verify the payment with the provider server-side and apply the result.
     *
     * The provider call happens outside the transaction. The resulting state is
     * re-checked under locks, so a concurrent expiry cannot sell released
     * numbers and an already-final payment cannot be downgraded.
     *
     * A PAYMENT_REVIEW order is never resolved by its owner: only an
     * administrator may resolve that incident (see resolveReview).
     */
    public function verify(User $user, Order $order, Payment $payment): Payment
    {
        $this->assertOwner($user, $order);
        $this->assertPaymentBelongsToOrder($order, $payment);
        $this->assertOrderVerifiable($order);

        if ($order->status === OrderStatus::PAYMENT_REVIEW) {
            throw PaymentConflictException::reviewRequiresAdmin();
        }

        return $this->verifyWithProvider($order, $payment, false);
    }

    /**
     * Resolve a PAYMENT_REVIEW incident on behalf of an administrator.
     *
     * The provider is always queried server-side; the browser never supplies
     * the resulting state. The same safety rules used by verify() are applied.
     */
    public function resolveReview(User $admin, Order $order, Payment $payment): Payment
    {
        if (! $admin->isAdmin()) {
            throw new AuthorizationException('Administrator role required.');
        }

        $this->assertPaymentBelongsToOrder($order, $payment);

        if ($order->status !== OrderStatus::PAYMENT_REVIEW) {
            throw PaymentConflictException::orderNotUnderReview();
        }

        return $this->verifyWithProvider($order, $payment, true);
    }

    private function verifyWithProvider(Order $order, Payment $payment, bool $resolveReview): Payment
    {
        try {
            $result = $this->gateway->verifyPayment($payment->external_id);
        } catch (PaymentGatewayTimeoutException) {
            Log::warning('Payment verification timed out; marking result as unknown.', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
            ]);

            $result = new PaymentStatus(
                $payment->external_id,
                PaymentState::UNKNOWN,
                $payment->amount,
                $payment->currency,
                [
                    'simulated' => config('rifacloud.payment_gateway') === 'fake',
                    'timeout' => true,
                ],
            );
        }

        $this->assertVerificationMatches($payment, $result);

        return DB::transaction(function () use ($order, $payment, $result, $resolveReview): Payment {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($resolveReview) {
                // An administrator may only resolve an order that is still
                // under review when the locked state is re-checked.
                if ($lockedOrder->status !== OrderStatus::PAYMENT_REVIEW) {
                    throw PaymentConflictException::orderNotUnderReview();
                }
            } else {
                $this->assertOrderVerifiable($lockedOrder);

                // The order may have moved to review while the provider call
                // was in flight; the owner still cannot resolve it.
                if ($lockedOrder->status === OrderStatus::PAYMENT_REVIEW) {
                    throw PaymentConflictException::reviewRequiresAdmin();
                }
            }

            return $this->applyResult($lockedOrder, $lockedPayment, $result);
        }, 3);
    }

    private function applyResult(Order $order, Payment $payment, PaymentStatus $result): Payment
    {
        $now = now();
        $currentPaymentStatus = $payment->status;
        $currentOrderStatus = $order->status;
        $targetPaymentStatus = $result->status;

        // The cutoff is based on time/status, never on whether the scheduler
        // has already run. A reservation that has expired is not sold, even
        // if its rows are still RESERVED because the job has not run yet.
        $reservationExpired = $currentOrderStatus === OrderStatus::EXPIRED
            || ($currentOrderStatus === OrderStatus::PENDING_PAYMENT
                && $order->expires_at !== null
                && ! $order->expires_at->isFuture());

        // A REJECTED order is terminal for sales, but a late provider approval
        // must still be recorded as a refund incident instead of being lost.
        $lateApproval = $reservationExpired || $currentOrderStatus === OrderStatus::REJECTED;

        if ($targetPaymentStatus === PaymentState::PENDING) {
            $this->assertPaymentTransition($currentPaymentStatus, PaymentState::PENDING);

            $this->persistPayment($payment, $result, PaymentState::PENDING, $now);

            return $payment;
        }

        $numbers = null;
        $evidence = [];

        if ($targetPaymentStatus === PaymentState::APPROVED) {
            $numbers = $this->lockOrderNumbers($order);

            if ($lateApproval) {
                // The provider approved after the order was no longer payable
                // (expired reservation or rejected attempt). Record the
                // approval durably and require a manual refund. Never reassign
                // released numbers.
                $targetPaymentStatus = PaymentState::REFUND_REQUIRED;
                $targetOrderStatus = OrderStatus::REQUIRES_REFUND;
                $evidence = [
                    'provider_approved' => true,
                    'late_approval' => true,
                ];

                Log::warning('Payment approved after the order stopped being payable; manual refund required.', [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                ]);
            } elseif ($this->numbersCanBeAssigned($order, $numbers)) {
                $targetOrderStatus = OrderStatus::PAID;
            } else {
                // Payment confirmed but the numbers cannot be assigned. Never
                // reassign them to another order; require a manual refund.
                $targetPaymentStatus = PaymentState::REFUND_REQUIRED;
                $targetOrderStatus = OrderStatus::REQUIRES_REFUND;
                $evidence = [
                    'provider_approved' => true,
                    'numbers_unavailable' => true,
                ];
            }
        } elseif ($targetPaymentStatus === PaymentState::REJECTED) {
            $numbers = $this->lockOrderNumbers($order);

            if ($numbers->contains(
                static fn (RaffleNumber $number): bool => $number->status === NumberStatus::SOLD,
            )) {
                throw PaymentConflictException::numbersNotReleasable();
            }

            // A rejection after expiry resolves as EXPIRED, not REJECTED, so
            // the status does not depend on whether the scheduler already ran.
            // The payment is still resolved as REJECTED and numbers are freed.
            $targetOrderStatus = $reservationExpired
                ? OrderStatus::EXPIRED
                : OrderStatus::REJECTED;
        } elseif ($targetPaymentStatus === PaymentState::UNKNOWN) {
            // Uncertainty after expiry must not become PAYMENT_REVIEW, because
            // that status intentionally holds numbers for a later approval and
            // could turn into a sale. Keep the reservation expired; the payment
            // remains UNKNOWN for review and any later approval requires refund.
            $targetOrderStatus = $reservationExpired
                ? OrderStatus::EXPIRED
                : OrderStatus::PAYMENT_REVIEW;
        } else {
            $targetOrderStatus = OrderStatus::REQUIRES_REFUND;
        }

        $this->assertPaymentTransition($currentPaymentStatus, $targetPaymentStatus);
        $this->assertOrderTransition($currentOrderStatus, $targetOrderStatus);

        if ($targetOrderStatus === OrderStatus::PAID && $currentOrderStatus !== OrderStatus::PAID) {
            $this->sellNumbers($order, $numbers, $now);
        }

        if ($targetPaymentStatus === PaymentState::REJECTED && $numbers !== null) {
            $this->releaseNumbers($order, $numbers, $now);
        }

        $this->persistPayment($payment, $result, $targetPaymentStatus, $now, $evidence);

        if ($currentOrderStatus !== $targetOrderStatus) {
            $attributes = ['status' => $targetOrderStatus];

            if ($targetOrderStatus === OrderStatus::PAID && $order->paid_at === null) {
                $attributes['paid_at'] = $now;
            }

            $order->forceFill($attributes)->save();
        }

        return $payment;
    }

    /**
     * @param  array<string, bool>  $evidence
     */
    private function persistPayment(
        Payment $payment,
        PaymentStatus $result,
        PaymentState $status,
        \DateTimeInterface $now,
        array $evidence = [],
    ): void {
        $payment->forceFill([
            'status' => $status,
            'verified_at' => $now,
            'response_data' => [
                ...$this->safeResponseData($result->raw),
                'provider_status' => $result->status->value,
                ...$evidence,
            ],
        ])->save();
    }

    /**
     * @param  Collection<int, RaffleNumber>  $numbers
     */
    private function numbersCanBeAssigned(Order $order, Collection $numbers): bool
    {
        if ($numbers->isEmpty()) {
            return false;
        }

        // Every number snapshotted in the order must still be locked and owned
        // by this order. Otherwise a partial sale would be possible even though
        // the payment covers the full order.
        if ($numbers->count() !== $order->items()->count()) {
            return false;
        }

        if ($numbers->contains(
            static fn (RaffleNumber $number): bool => ! in_array(
                $number->status,
                [NumberStatus::RESERVED, NumberStatus::SOLD],
                true,
            ),
        )) {
            return false;
        }

        $allReserved = $numbers->every(
            static fn (RaffleNumber $number): bool => $number->status === NumberStatus::RESERVED,
        );
        $allSold = $numbers->every(
            static fn (RaffleNumber $number): bool => $number->status === NumberStatus::SOLD,
        );

        return $allReserved || $allSold;
    }

    /**
     * @param  Collection<int, RaffleNumber>|null  $numbers
     */
    private function sellNumbers(Order $order, ?Collection $numbers, \DateTimeInterface $now): void
    {
        if ($numbers === null) {
            throw PaymentConflictException::numbersUpdateFailed();
        }

        $reserved = $numbers->filter(
            static fn (RaffleNumber $number): bool => $number->status === NumberStatus::RESERVED,
        );

        if ($reserved->isEmpty()) {
            return;
        }

        $ids = $reserved->modelKeys();

        $affected = RaffleNumber::query()
            ->whereKey($ids)
            ->where('order_id', $order->id)
            ->where('status', NumberStatus::RESERVED->value)
            ->update([
                'status' => NumberStatus::SOLD->value,
                'sold_at' => $now,
                'reserved_until' => null,
                'updated_at' => $now,
            ]);

        if ($affected !== count($ids)) {
            throw PaymentConflictException::numbersUpdateFailed();
        }
    }

    /**
     * @param  Collection<int, RaffleNumber>  $numbers
     */
    private function releaseNumbers(Order $order, Collection $numbers, \DateTimeInterface $now): void
    {
        $reserved = $numbers->filter(
            static fn (RaffleNumber $number): bool => $number->status === NumberStatus::RESERVED,
        );

        if ($reserved->isEmpty()) {
            return;
        }

        $ids = $reserved->modelKeys();

        $affected = RaffleNumber::query()
            ->whereKey($ids)
            ->where('order_id', $order->id)
            ->where('status', NumberStatus::RESERVED->value)
            ->update([
                'status' => NumberStatus::AVAILABLE->value,
                'reserved_at' => null,
                'reserved_until' => null,
                'sold_at' => null,
                'order_id' => null,
                'updated_at' => $now,
            ]);

        if ($affected !== count($ids)) {
            throw PaymentConflictException::numbersUpdateFailed();
        }
    }

    /**
     * @return Collection<int, RaffleNumber>
     */
    private function lockOrderNumbers(Order $order): Collection
    {
        return RaffleNumber::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function assertOwner(User $user, Order $order): void
    {
        if ((int) $order->user_id !== (int) $user->id) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$order->id]);
        }
    }

    private function assertPaymentBelongsToOrder(Order $order, Payment $payment): void
    {
        if ((int) $payment->order_id !== (int) $order->id) {
            throw (new ModelNotFoundException)->setModel(Payment::class, [$payment->id]);
        }
    }

    private function assertOrderVerifiable(Order $order): void
    {
        // EXPIRED and REJECTED orders remain verifiable so a real provider
        // approval is never discarded. The result is applied as a manual-refund
        // case, never as a sale, because the order is no longer payable.
        if (! in_array($order->status, [
            OrderStatus::PENDING_PAYMENT,
            OrderStatus::PAYMENT_REVIEW,
            OrderStatus::EXPIRED,
            OrderStatus::REJECTED,
        ], true)) {
            throw PaymentConflictException::orderNotVerifiable();
        }
    }

    private function assertVerificationMatches(Payment $payment, PaymentStatus $result): void
    {
        if (! hash_equals($payment->external_id, $result->externalId)) {
            throw PaymentConflictException::verificationMismatch();
        }

        if ($payment->amount !== $result->amount || $payment->currency !== $result->currency) {
            throw PaymentConflictException::verificationMismatch();
        }
    }

    private function assertPaymentTransition(PaymentState $from, PaymentState $to): void
    {
        if ($from === $to) {
            return;
        }

        if (! $from->canTransitionTo($to)) {
            throw PaymentConflictException::invalidTransition('payment', $from->value, $to->value);
        }
    }

    private function assertOrderTransition(OrderStatus $from, OrderStatus $to): void
    {
        if ($from === $to) {
            return;
        }

        if (! $from->canTransitionTo($to)) {
            throw PaymentConflictException::invalidTransition('order', $from->value, $to->value);
        }
    }

    /**
     * Keep only a small allowlist of non-sensitive provider result data.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function safeResponseData(array $raw): array
    {
        $safe = Arr::only($raw, ['simulated', 'mode', 'timeout']);

        return array_filter(
            $safe,
            static fn (mixed $value): bool => is_scalar($value) || $value === null,
        );
    }
}
