<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use RuntimeException;

/**
 * Business conflict (HTTP 409) with the same `code` values React already
 * consumes from the monolith.
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

    public static function fromCode(string $code, string $message = '', array $context = []): self
    {
        return new self(
            $message !== '' ? $message : 'No se pudo procesar el pago en su estado actual.',
            $code,
            $context,
        );
    }

    public static function orderNotPayable(): self
    {
        return new self(
            'La orden no está disponible para iniciar un pago.',
            'ORDER_NOT_PAYABLE',
        );
    }

    public static function orderExpired(): self
    {
        return new self(
            'La reserva de la orden ya venció.',
            'ORDER_EXPIRED',
        );
    }

    public static function paymentUncertain(): self
    {
        return new self(
            'El pago anterior está en revisión. No se iniciará un nuevo cobro.',
            'PAYMENT_UNCERTAIN',
        );
    }

    public static function paymentAlreadyFinal(PaymentState $state): self
    {
        return new self(
            'La orden ya tiene un pago en estado '.$state->value.'. No se iniciará un nuevo cobro.',
            'PAYMENT_ALREADY_FINAL',
            ['payment_status' => $state->value],
        );
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

    public static function verificationMismatch(): self
    {
        return new self(
            'La verificación del pago no coincide con la orden.',
            'PAYMENT_VERIFICATION_MISMATCH',
        );
    }
}
