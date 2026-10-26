<?php

namespace App\Modules\Payments\Exceptions;

use App\Modules\Payments\Enums\PaymentState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentConflictException extends RuntimeException
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

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            ...$this->context,
        ], JsonResponse::HTTP_CONFLICT);
    }
}
