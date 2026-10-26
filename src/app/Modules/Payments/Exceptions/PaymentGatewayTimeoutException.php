<?php

namespace App\Modules\Payments\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentGatewayTimeoutException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'El proveedor de pagos no respondió. Inténtalo de nuevo.',
            'code' => 'PAYMENT_PROVIDER_TIMEOUT',
        ], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
    }
}
