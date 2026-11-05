<?php

namespace App\Modules\Payments\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Enums\NumberStatus;
use App\Modules\Numbers\Models\RaffleNumber;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\DTOs\PaymentStatus;
use App\Modules\Payments\DTOs\WebhookEvent;
use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Enums\PaymentWebhookStatus;
use App\Modules\Payments\Exceptions\PaymentConflictException;
use App\Modules\Payments\Exceptions\PaymentGatewayException;
use App\Modules\Payments\Exceptions\PaymentGatewayTimeoutException;
use App\Modules\Payments\Exceptions\PaymentNotFoundException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
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

            $session = $this->gateway->createCheckout($lockedOrder->loadMissing('raffle'));

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
                    ...$session->raw,
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

    /**
     * Process an already authenticated provider notification.
     *
     * The payload is never trusted: the gateway has validated the signature
     * and fetched the authoritative payment. The event is persisted with a
     * unique idempotency key, then the existing state machine is applied under
     * order/payment locks.
     */
    public function processWebhook(WebhookEvent $event): PaymentWebhookEvent
    {
        if ($event->externalId === null || $event->externalId === '' || $event->payment === null) {
            $reason = $event->eventType === 'payment'
                ? 'incomplete_payment_notification'
                : 'unsupported_topic';

            if ($reason === 'unsupported_topic') {
                Log::info('Unsupported Mercado Pago notification topic acknowledged.', [
                    'event_type' => $event->eventType,
                ]);
            }

            return $this->recordWebhookEvent($event, PaymentWebhookStatus::IGNORED, [
                'reason' => $reason,
            ]);
        }

        $payment = Payment::query()
            ->where('provider', PaymentProvider::MERCADOPAGO)
            ->where('external_id', $event->externalId)
            ->first();

        if ($payment === null) {
            return $this->recordWebhookEvent($event, PaymentWebhookStatus::IGNORED, [
                'reason' => 'unknown_external_reference',
            ]);
        }

        if (! hash_equals($payment->external_id, $event->payment->externalId)
            || $payment->amount !== $event->payment->amount
            || $payment->currency !== $event->payment->currency) {
            Log::warning('Webhook data does not match the stored payment; ignored.', [
                'order_id' => $payment->order_id,
                'payment_id' => $payment->id,
            ]);

            return $this->recordWebhookEvent($event, PaymentWebhookStatus::IGNORED, [
                'reason' => 'verification_mismatch',
            ]);
        }

        try {
            return DB::transaction(function () use ($event, $payment): PaymentWebhookEvent {
                $lockedOrder = Order::query()
                    ->whereKey($payment->order_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedPayment = Payment::query()
                    ->whereKey($payment->id)
                    ->where('order_id', $payment->order_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $eventRow = $this->lockWebhookEvent($event);

                if (in_array($eventRow->status, [
                    PaymentWebhookStatus::PROCESSED,
                    PaymentWebhookStatus::IGNORED,
                ], true)) {
                    return $eventRow;
                }

                if ($eventRow->status === PaymentWebhookStatus::FAILED) {
                    $eventRow->forceFill([
                        'status' => PaymentWebhookStatus::RECEIVED,
                        'processed_at' => null,
                    ])->save();
                }

                $result = $event->payment;

                // A later mediation must never downgrade an approved payment.
                // It is a refund incident, not an uncertain result.
                if ($result->status === PaymentState::UNKNOWN
                    && $lockedPayment->status === PaymentState::APPROVED) {
                    if (($result->raw['mp_status'] ?? null) === 'in_mediation') {
                        $result = new PaymentStatus(
                            $result->externalId,
                            PaymentState::REFUND_REQUIRED,
                            $result->amount,
                            $result->currency,
                            [
                                ...$result->raw,
                                'mediation_after_approval' => true,
                            ],
                        );
                    } else {
                        return $this->markWebhookEvent(
                            $eventRow,
                            PaymentWebhookStatus::IGNORED,
                            'stale_event',
                        );
                    }
                }

                // Out-of-order notifications must not revert a more advanced
                // payment state. The state machine is the final authority.
                if ($result->status === PaymentState::PENDING
                    && $lockedPayment->status !== PaymentState::PENDING) {
                    return $this->markWebhookEvent(
                        $eventRow,
                        PaymentWebhookStatus::IGNORED,
                        'stale_event',
                    );
                }

                if ($result->status === PaymentState::REJECTED
                    && in_array($lockedPayment->status, [
                        PaymentState::APPROVED,
                        PaymentState::REFUND_REQUIRED,
                    ], true)) {
                    return $this->markWebhookEvent(
                        $eventRow,
                        PaymentWebhookStatus::IGNORED,
                        'stale_event',
                    );
                }

                $this->applyResult($lockedOrder, $lockedPayment, $result);

                $eventRow->forceFill([
                    'status' => PaymentWebhookStatus::PROCESSED,
                    'processed_at' => now(),
                    'payload' => [
                        ...($eventRow->payload ?? []),
                        ...$this->safeResponseData($result->raw),
                        'result_status' => $result->status->value,
                    ],
                ])->save();

                return $eventRow;
            }, 3);
        } catch (PaymentConflictException $exception) {
            Log::warning('Webhook ignored because it conflicts with the current state.', [
                'order_id' => $payment->order_id,
                'payment_id' => $payment->id,
                'code' => $exception->errorCode,
            ]);

            return $this->recordWebhookEvent($event, PaymentWebhookStatus::IGNORED, [
                'reason' => $exception->errorCode,
            ]);
        } catch (\Throwable $exception) {
            // The business transaction has already rolled back. Persist the
            // failed attempt in an isolated write so the retry is auditable,
            // then rethrow so Mercado Pago receives a retryable error.
            $this->recordFailedWebhookEvent($event, $exception);

            throw $exception;
        }
    }

    /**
     * Reconcile payments whose provider result is still unknown or pending.
     *
     * This never creates a checkout or a charge. It only re-queries the
     * provider and applies the existing state machine. Dry-run performs no
     * provider calls and no writes.
     */
    public function reconcile(int $limit = 50, bool $dryRun = false): int
    {
        $payments = Payment::query()
            ->whereIn('status', [
                PaymentState::PENDING->value,
                PaymentState::UNKNOWN->value,
            ])
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        if ($dryRun) {
            return $payments->count();
        }

        $reconciled = 0;

        foreach ($payments as $payment) {
            $order = Order::query()->find($payment->order_id);

            if ($order === null) {
                continue;
            }

            try {
                $this->verifyWithProvider(
                    $order,
                    $payment,
                    $order->status === OrderStatus::PAYMENT_REVIEW,
                );
                $reconciled++;
            } catch (PaymentConflictException $exception) {
                Log::warning('Payment reconciliation was skipped.', [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'code' => $exception->errorCode,
                ]);
            } catch (PaymentGatewayException $exception) {
                Log::warning('Payment reconciliation failed with a provider error.', [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                ]);
            }
        }

        return $reconciled;
    }

    /**
     * Re-audit payments already APPROVED to detect additional approved charges.
     *
     * This is a provider query plus the existing manual-refund incident
     * transition. It never creates a checkout, a charge or a refund. A failed
     * or unconfirmed provider query is reported as an error and is never
     * recorded as evidence that no additional payments exist.
     *
     * @return array{inspected: int, incidents: int, errors: int}
     */
    public function reconcileApproved(int $limit = 50, bool $dryRun = false): array
    {
        $payments = Payment::query()
            ->where('status', PaymentState::APPROVED->value)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $result = [
            'inspected' => $payments->count(),
            'incidents' => 0,
            'errors' => 0,
        ];

        if ($dryRun) {
            return $result;
        }

        foreach ($payments as $payment) {
            try {
                $status = $this->gateway->verifyPayment($payment->external_id);
            } catch (PaymentNotFoundException) {
                $result['errors']++;
                Log::error('Approved-payment audit could not find the provider payment.', [
                    'order_id' => $payment->order_id,
                    'payment_id' => $payment->id,
                ]);

                continue;
            } catch (PaymentGatewayTimeoutException|PaymentGatewayException) {
                $result['errors']++;
                Log::error('Approved-payment audit could not confirm the approved payment count.', [
                    'order_id' => $payment->order_id,
                    'payment_id' => $payment->id,
                ]);

                continue;
            }

            $approvedCount = $status->raw['mp_approved_payment_count'] ?? null;

            // The payment is already APPROVED in our database, so a confirmed
            // search must include at least one approval. A zero, missing or
            // invalid count is an unconfirmed audit, not a clean result.
            if (! is_int($approvedCount) || $approvedCount < 1) {
                $result['errors']++;
                Log::error('Approved-payment audit returned an unconfirmed count.', [
                    'order_id' => $payment->order_id,
                    'payment_id' => $payment->id,
                ]);

                continue;
            }

            if ($approvedCount <= 1) {
                continue;
            }

            if ($this->escalateMultipleApprovedPayments($payment, $status, $approvedCount)) {
                $result['incidents']++;
                Log::warning('Approved-payment audit found multiple approved charges; manual review required.', [
                    'order_id' => $payment->order_id,
                    'payment_id' => $payment->id,
                    'approved_payment_count' => $approvedCount,
                ]);
            }
        }

        return $result;
    }

    /**
     * Apply the manual-refund incident for an already APPROVED payment after a
     * reconciliation audit. Numbers are never released or reassigned.
     */
    private function escalateMultipleApprovedPayments(
        Payment $payment,
        PaymentStatus $result,
        int $approvedCount,
    ): bool {
        return DB::transaction(function () use ($payment, $result, $approvedCount): bool {
            $lockedOrder = Order::query()
                ->whereKey($payment->order_id)
                ->lockForUpdate()
                ->first();

            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->where('order_id', $payment->order_id)
                ->lockForUpdate()
                ->first();

            if ($lockedOrder === null || $lockedPayment === null) {
                return false;
            }

            // A concurrent webhook may have changed the state since the audit
            // read it. Only a still-APPROVED payment can become an incident.
            if ($lockedPayment->status !== PaymentState::APPROVED) {
                return false;
            }

            if (! $lockedOrder->status->canTransitionTo(OrderStatus::REQUIRES_REFUND)) {
                return false;
            }

            $this->assertPaymentTransition($lockedPayment->status, PaymentState::REFUND_REQUIRED);
            $this->assertOrderTransition($lockedOrder->status, OrderStatus::REQUIRES_REFUND);

            $now = now();

            $this->persistPayment($lockedPayment, $result, PaymentState::REFUND_REQUIRED, $now, [
                'provider_approved' => true,
                'multiple_approved_payments' => true,
                'approved_payment_count' => $approvedCount,
                'detected_by_reconciliation' => true,
            ]);

            $lockedOrder->forceFill([
                'status' => OrderStatus::REQUIRES_REFUND,
            ])->save();

            return true;
        }, 3);
    }

    /**
     * Insert the event row under the current transaction and lock it.
     *
     * The unique constraint is what serializes simultaneous deliveries of the
     * same notification.
     */
    private function lockWebhookEvent(WebhookEvent $event): PaymentWebhookEvent
    {
        $now = now();
        $providerEventId = $this->bounded($event->providerEventId, 255);

        DB::table('payment_webhook_events')->insertOrIgnore([
            'provider' => PaymentProvider::MERCADOPAGO->value,
            'provider_event_id' => $providerEventId,
            'event_type' => $this->bounded($event->eventType, 100),
            'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
            'processed_at' => null,
            'status' => PaymentWebhookStatus::RECEIVED->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return PaymentWebhookEvent::query()
            ->where('provider', PaymentProvider::MERCADOPAGO->value)
            ->where('provider_event_id', $providerEventId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function recordWebhookEvent(
        WebhookEvent $event,
        PaymentWebhookStatus $status,
        array $extra = [],
    ): PaymentWebhookEvent {
        $attributes = [
            'event_type' => $this->bounded($event->eventType, 100),
            'payload' => [
                ...$event->payload,
                ...$extra,
            ],
            'status' => $status,
            'processed_at' => now(),
        ];

        $key = [
            'provider' => PaymentProvider::MERCADOPAGO->value,
            'provider_event_id' => $this->bounded($event->providerEventId, 255),
        ];

        try {
            return PaymentWebhookEvent::query()->updateOrCreate($key, $attributes);
        } catch (QueryException) {
            // A simultaneous delivery inserted the same unique key first.
            return PaymentWebhookEvent::query()->updateOrCreate($key, $attributes);
        }
    }

    /**
     * Record a failed processing attempt after the business transaction rolled
     * back. This write is isolated on purpose: it must not keep the order,
     * payment or numbers in a partially applied state. Provider messages are
     * never stored; only the exception class is used as a safe reason code.
     */
    private function recordFailedWebhookEvent(WebhookEvent $event, \Throwable $exception): void
    {
        try {
            $key = [
                'provider' => PaymentProvider::MERCADOPAGO->value,
                'provider_event_id' => $this->bounded($event->providerEventId, 255),
            ];

            $attributes = [
                'event_type' => $this->bounded($event->eventType, 100),
                'payload' => [
                    ...$event->payload,
                    'reason' => 'processing_failed',
                    'failure' => class_basename($exception),
                ],
                'status' => PaymentWebhookStatus::FAILED,
                'processed_at' => now(),
            ];

            $row = PaymentWebhookEvent::query()->firstOrCreate($key, $attributes);

            if (in_array($row->status, [
                PaymentWebhookStatus::PROCESSED,
                PaymentWebhookStatus::IGNORED,
            ], true)) {
                return;
            }

            $row->forceFill($attributes)->save();
        } catch (\Throwable $recordingFailure) {
            Log::error('Webhook processing failed and the failure could not be recorded.', [
                'provider_event_id' => $event->providerEventId,
                'failure' => class_basename($exception),
                'recording_failure' => class_basename($recordingFailure),
            ]);
        }
    }

    private function markWebhookEvent(
        PaymentWebhookEvent $event,
        PaymentWebhookStatus $status,
        string $reason,
    ): PaymentWebhookEvent {
        $event->forceFill([
            'status' => $status,
            'processed_at' => now(),
            'payload' => [
                ...($event->payload ?? []),
                'reason' => $reason,
            ],
        ])->save();

        return $event;
    }

    private function verifyWithProvider(Order $order, Payment $payment, bool $resolveReview): Payment
    {
        try {
            $result = $this->gateway->verifyPayment($payment->external_id);
        } catch (PaymentNotFoundException) {
            // The buyer has not completed the checkout yet. This is not a
            // rejection and it is not uncertain: leave the order and numbers
            // untouched so the payment can be verified later.
            Log::info('No provider payment found yet; order left unchanged.', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
            ]);

            return $payment;
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

        // A later mediation on an approved payment is a refund incident, never
        // a downgrade to UNKNOWN.
        if ($targetPaymentStatus === PaymentState::UNKNOWN
            && $currentPaymentStatus === PaymentState::APPROVED) {
            $targetPaymentStatus = PaymentState::REFUND_REQUIRED;
            $evidence = ['mediation_after_approval' => true];
        }

        if ($targetPaymentStatus === PaymentState::APPROVED) {
            $numbers = $this->lockOrderNumbers($order);

            // The count is only trustworthy when the gateway explicitly
            // confirmed it. A failed or incomplete `payments/search` must
            // never be treated as "exactly one payment": keep the approval as
            // a manual-review incident and leave every number untouched.
            $approvedCountUnconfirmed = ($result->raw['mp_approved_payment_count_unconfirmed'] ?? false) === true;
            $approvedCount = (int) ($result->raw['mp_approved_payment_count'] ?? 1);

            if ($approvedCountUnconfirmed) {
                $targetPaymentStatus = PaymentState::REFUND_REQUIRED;
                $targetOrderStatus = OrderStatus::REQUIRES_REFUND;
                $evidence = [
                    'provider_approved' => true,
                    'approved_payment_count_unconfirmed' => true,
                ];

                if ($lateApproval) {
                    $evidence['late_approval'] = true;
                }

                Log::warning('Approved payment count could not be confirmed; manual review required.', [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                ]);
            } elseif ($approvedCount > 1) {
                // More than one approved charge exists for this order. The
                // platform must not choose one silently or refund
                // automatically: preserve the evidence and require manual
                // resolution.
                $targetPaymentStatus = PaymentState::REFUND_REQUIRED;
                $targetOrderStatus = OrderStatus::REQUIRES_REFUND;
                $evidence = [
                    'provider_approved' => true,
                    'multiple_approved_payments' => true,
                    'approved_payment_count' => $approvedCount,
                ];

                if ($lateApproval) {
                    $evidence['late_approval'] = true;
                }

                Log::warning('Multiple approved payments found for one order; manual review required.', [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'approved_payment_count' => $approvedCount,
                ]);
            } elseif ($lateApproval) {
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
            $providerRefundStatus = (string) ($result->raw['mp_status'] ?? '');

            if (in_array($providerRefundStatus, ['refunded', 'charged_back', 'in_mediation'], true)) {
                $evidence = [
                    ...$evidence,
                    'refund_incident' => true,
                    'provider_refund_status' => $providerRefundStatus,
                ];
            }
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
     * @param  array<string, mixed>  $evidence
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
                ...($payment->response_data ?? []),
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
     * Bound a value to its database column length. Signed notifications are
     * authenticated, but a long topic must not turn into a SQL error.
     */
    private function bounded(string $value, int $maximum): string
    {
        return mb_substr($value, 0, $maximum);
    }

    /**
     * Keep only a small allowlist of non-sensitive provider result data.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function safeResponseData(array $raw): array
    {
        $safe = Arr::only($raw, [
            // Simulated gateway.
            'simulated',
            'mode',
            'timeout',
            // Mercado Pago checkout metadata.
            'preference_id',
            'sandbox',
            'live_mode',
            // Mercado Pago payment evidence (no payer or card data).
            'mp_payment_id',
            'mp_status',
            'mp_status_detail',
            'mp_payment_method_id',
            'mp_payment_type_id',
            'mp_external_reference',
            'mp_date_approved',
            'mp_date_created',
            'mp_date_last_updated',
            'mp_live_mode',
            'mp_approved_payment_count',
            'mp_approved_payment_ids',
            'mp_approved_payment_count_unconfirmed',
            'mp_payment_ids',
            'mediation_after_approval',
            'transaction_amount',
            'currency_id',
        ]);

        return array_filter(
            $safe,
            static function (mixed $value): bool {
                // A null means "the provider did not report this field". Drop
                // it instead of persisting an ambiguous null; false and 0 are
                // real provider values and must be preserved.
                if ($value === null) {
                    return false;
                }

                if (is_scalar($value)) {
                    return true;
                }

                if (! is_array($value)) {
                    return false;
                }

                foreach ($value as $item) {
                    if (! is_scalar($item)) {
                        return false;
                    }
                }

                return true;
            },
        );
    }
}
