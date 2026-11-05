<?php

namespace App\Modules\Payments\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Non-retryable provider rejection or configuration error (for example an
 * invalid access token). The browser receives a generic message; provider
 * details stay in the server logs.
 */
class PaymentGatewayException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'No se pudo iniciar el pago con el proveedor. Inténtalo de nuevo.',
            'code' => 'PAYMENT_PROVIDER_ERROR',
        ], JsonResponse::HTTP_BAD_GATEWAY);
    }
}
