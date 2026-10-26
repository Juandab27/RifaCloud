<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\DTOs\CheckoutSession;
use App\Modules\Payments\DTOs\PaymentStatus;
use App\Modules\Payments\DTOs\WebhookEvent;
use App\Modules\Payments\Enums\PaymentState;
use App\Modules\Payments\Exceptions\PaymentGatewayTimeoutException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Deterministic local gateway used in P1 (02-technical-design.md §7).
 *
 * The outcome is selected with PAYMENT_FAKE_OUTCOME. TIMEOUT is represented as
 * a gateway exception so the payment service can mark the result as uncertain
 * instead of treating it as a declined payment.
 */
class FakePaymentGateway implements PaymentGateway
{
    private const EXTERNAL_ID_PATTERN = '/^SIM-([A-Z]{3})-(\d+)-[A-Z0-9]+$/';

    public function createCheckout(Order $order): CheckoutSession
    {
        $externalId = sprintf(
            'SIM-COP-%d-%s',
            $order->total_amount,
            Str::ulid(),
        );

        return new CheckoutSession(
            $externalId,
            rtrim((string) config('app.url'), '/').'/checkout/simulated/'.$externalId,
        );
    }

    public function verifyPayment(string $externalId): PaymentStatus
    {
        if (preg_match(self::EXTERNAL_ID_PATTERN, $externalId, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid simulated payment external ID.');
        }

        $currency = $matches[1];
        $amount = (int) $matches[2];
        $mode = strtoupper((string) config('rifacloud.payment_fake_outcome', 'UNKNOWN'));

        if ($mode === 'TIMEOUT') {
            throw new PaymentGatewayTimeoutException('Simulated payment provider timeout.');
        }

        $status = match ($mode) {
            'APPROVED' => PaymentState::APPROVED,
            'REJECTED' => PaymentState::REJECTED,
            'UNKNOWN' => PaymentState::UNKNOWN,
            default => PaymentState::UNKNOWN,
        };

        return new PaymentStatus(
            $externalId,
            $status,
            $amount,
            $currency,
            [
                'simulated' => true,
                'mode' => $mode,
            ],
        );
    }

    public function parseWebhook(array $payload): WebhookEvent
    {
        throw new LogicException('Simulated webhooks are not part of Phase 2 Block B.');
    }
}
