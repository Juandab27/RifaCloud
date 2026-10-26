<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PaymentGatewaySafetyTest extends TestCase
{
    public function test_fake_gateway_is_rejected_in_production(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PAYMENT_GATEWAY=fake is not allowed in production');

        AppServiceProvider::assertPaymentGatewayIsAllowed('production', 'fake');
    }

    public function test_fake_gateway_is_allowed_outside_production(): void
    {
        AppServiceProvider::assertPaymentGatewayIsAllowed('local', 'fake');
        AppServiceProvider::assertPaymentGatewayIsAllowed('testing', 'fake');

        $this->addToAssertionCount(2);
    }

    public function test_non_fake_gateway_is_not_rejected_by_the_safety_guard(): void
    {
        AppServiceProvider::assertPaymentGatewayIsAllowed('production', 'mercadopago');

        $this->addToAssertionCount(1);
    }
}
