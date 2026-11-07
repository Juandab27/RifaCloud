<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Orders;

use RuntimeException;

/**
 * 409 conflict raised by the payment-result command.
 *
 * Codes and context are the same App\Modules\Payments\Exceptions\
 * PaymentConflictException uses, so payments-service can forward them to React
 * without translation.
 */
final class PaymentConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function orderNotVerifiable(): self
    {
        return new self(
            'La orden no puede verificarse en su estado actual.',
            'ORDER_NOT_VERIFIABLE',
        );
    }

    public static function reviewRequiresAdmin(): self
    {
        return new self(
            'La orden está en revisión de pago y solo un administrador puede resolverla.',
            'PAYMENT_REVIEW_REQUIRES_ADMIN',
        );
    }

    public static function orderNotUnderReview(): self
    {
        return new self(
            'La orden no está en revisión de pago.',
            'ORDER_NOT_UNDER_REVIEW',
        );
    }

    public static function verificationMismatch(): self
    {
        return new self(
            'La verificación del pago no coincide con la orden.',
            'PAYMENT_VERIFICATION_MISMATCH',
        );
    }

    public static function invalidTransition(string $entity, string $from, string $to): self
    {
        return new self(
            'Transición de estado no permitida.',
            'INVALID_STATE_TRANSITION',
            [
                'entity' => $entity,
                'from' => $from,
                'to' => $to,
            ],
        );
    }

    public static function numbersNotReleasable(): self
    {
        return new self(
            'La orden tiene números vendidos y no puede liberarse.',
            'NUMBERS_NOT_RELEASABLE',
        );
    }

    public static function numbersUpdateFailed(): self
    {
        return new self(
            'No se pudieron actualizar los números de la orden.',
            'NUMBERS_UPDATE_FAILED',
        );
    }
}
