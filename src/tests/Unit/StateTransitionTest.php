<?php

namespace Tests\Unit;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentState;
use PHPUnit\Framework\TestCase;

class StateTransitionTest extends TestCase
{
    public function test_payment_state_machine_allows_only_explicit_transitions(): void
    {
        $this->assertTrue(PaymentState::PENDING->canTransitionTo(PaymentState::PENDING));
        $this->assertTrue(PaymentState::PENDING->canTransitionTo(PaymentState::APPROVED));
        $this->assertTrue(PaymentState::PENDING->canTransitionTo(PaymentState::REJECTED));
        $this->assertTrue(PaymentState::PENDING->canTransitionTo(PaymentState::UNKNOWN));
        $this->assertTrue(PaymentState::PENDING->canTransitionTo(PaymentState::REFUND_REQUIRED));

        $this->assertTrue(PaymentState::UNKNOWN->canTransitionTo(PaymentState::APPROVED));
        $this->assertTrue(PaymentState::UNKNOWN->canTransitionTo(PaymentState::REJECTED));
        $this->assertTrue(PaymentState::UNKNOWN->canTransitionTo(PaymentState::REFUND_REQUIRED));
        $this->assertFalse(PaymentState::UNKNOWN->canTransitionTo(PaymentState::PENDING));

        $this->assertFalse(PaymentState::APPROVED->canTransitionTo(PaymentState::REJECTED));
        $this->assertFalse(PaymentState::APPROVED->canTransitionTo(PaymentState::UNKNOWN));
        $this->assertFalse(PaymentState::REJECTED->canTransitionTo(PaymentState::APPROVED));
        $this->assertTrue(PaymentState::REJECTED->canTransitionTo(PaymentState::REFUND_REQUIRED));
        $this->assertFalse(PaymentState::REFUND_REQUIRED->canTransitionTo(PaymentState::APPROVED));
    }

    public function test_payment_states_block_reservation_release_when_uncertain_or_final(): void
    {
        $this->assertTrue(PaymentState::UNKNOWN->blocksReservationRelease());
        $this->assertTrue(PaymentState::APPROVED->blocksReservationRelease());
        $this->assertTrue(PaymentState::REFUND_REQUIRED->blocksReservationRelease());
        $this->assertFalse(PaymentState::PENDING->blocksReservationRelease());
        $this->assertFalse(PaymentState::REJECTED->blocksReservationRelease());
    }

    public function test_order_state_machine_allows_only_explicit_transitions(): void
    {
        $this->assertTrue(OrderStatus::PENDING_PAYMENT->canTransitionTo(OrderStatus::PAID));
        $this->assertTrue(OrderStatus::PENDING_PAYMENT->canTransitionTo(OrderStatus::REJECTED));
        $this->assertTrue(OrderStatus::PENDING_PAYMENT->canTransitionTo(OrderStatus::EXPIRED));
        $this->assertTrue(OrderStatus::PENDING_PAYMENT->canTransitionTo(OrderStatus::PAYMENT_REVIEW));
        $this->assertTrue(OrderStatus::PENDING_PAYMENT->canTransitionTo(OrderStatus::REQUIRES_REFUND));

        $this->assertTrue(OrderStatus::PAYMENT_REVIEW->canTransitionTo(OrderStatus::PAID));
        $this->assertTrue(OrderStatus::PAYMENT_REVIEW->canTransitionTo(OrderStatus::REJECTED));
        $this->assertTrue(OrderStatus::PAYMENT_REVIEW->canTransitionTo(OrderStatus::REQUIRES_REFUND));
        $this->assertFalse(OrderStatus::PAYMENT_REVIEW->canTransitionTo(OrderStatus::EXPIRED));

        $this->assertFalse(OrderStatus::PAID->canTransitionTo(OrderStatus::REJECTED));
        $this->assertFalse(OrderStatus::REJECTED->canTransitionTo(OrderStatus::PAID));
        $this->assertTrue(OrderStatus::REJECTED->canTransitionTo(OrderStatus::REQUIRES_REFUND));
        $this->assertFalse(OrderStatus::REJECTED->canTransitionTo(OrderStatus::PENDING_PAYMENT));
        $this->assertFalse(OrderStatus::EXPIRED->canTransitionTo(OrderStatus::PAID));
        $this->assertTrue(OrderStatus::EXPIRED->canTransitionTo(OrderStatus::REQUIRES_REFUND));
        $this->assertFalse(OrderStatus::REQUIRES_REFUND->canTransitionTo(OrderStatus::PAID));
    }
}
