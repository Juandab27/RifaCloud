<?php

namespace App\Modules\Orders\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OrderConflictException extends RuntimeException
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

    public static function raffleNotAvailable(): self
    {
        return new self(
            'La rifa no está disponible para comprar.',
            'RAFFLE_NOT_AVAILABLE',
        );
    }

    public static function raffleNotStarted(): self
    {
        return new self(
            'La rifa aún no está disponible para comprar.',
            'RAFFLE_NOT_STARTED',
        );
    }

    public static function raffleEnded(): self
    {
        return new self(
            'La rifa ya finalizó y no admite nuevas compras.',
            'RAFFLE_ENDED',
        );
    }

    public static function raffleScheduleInvalid(): self
    {
        return new self(
            'La rifa tiene un calendario inválido y no admite compras.',
            'RAFFLE_SCHEDULE_INVALID',
        );
    }

    /**
     * @param  list<int>  $numbers
     */
    public static function numbersUnavailable(array $numbers): self
    {
        return new self(
            'Algunos números ya no están disponibles. Actualiza la lista e inténtalo de nuevo.',
            'NUMBERS_UNAVAILABLE',
            ['unavailable_numbers' => array_values($numbers)],
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
