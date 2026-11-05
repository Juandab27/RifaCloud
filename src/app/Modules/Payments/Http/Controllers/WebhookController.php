<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Exceptions\InvalidWebhookPayloadException;
use App\Modules\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Modules\Payments\Exceptions\PaymentGatewayException;
use App\Modules\Payments\Exceptions\PaymentGatewayTimeoutException;
use App\Modules\Payments\Exceptions\PaymentNotFoundException;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentService $paymentService,
    ) {}

    /**
     * Mercado Pago notification endpoint.
     *
     * Responses follow the provider contract:
     * - 200: accepted (processed, duplicate or intentionally ignored)
     * - 400: malformed payload
     * - 401: missing or invalid signature
     * - 503: provider temporarily unavailable; Mercado Pago should retry
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->response('invalid_payload', Response::HTTP_BAD_REQUEST);
        }

        if (! is_array($payload) || $payload === []) {
            return $this->response('invalid_payload', Response::HTTP_BAD_REQUEST);
        }

        // Some notifications expose data.id/type only in the query string.
        if (! isset($payload['data']['id']) && $request->query('data.id') !== null) {
            $payload['data'] = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $payload['data']['id'] = $request->query('data.id');
        }

        if (! isset($payload['type']) && $request->query('type') !== null) {
            $payload['type'] = $request->query('type');
        }

        $headers = [
            'x-signature' => (string) $request->header('x-signature', ''),
            'x-request-id' => (string) $request->header('x-request-id', ''),
        ];

        try {
            $event = $this->gateway->parseWebhook($payload, $headers);
        } catch (InvalidWebhookSignatureException) {
            return $this->response('invalid_signature', Response::HTTP_UNAUTHORIZED);
        } catch (InvalidWebhookPayloadException) {
            return $this->response('invalid_payload', Response::HTTP_BAD_REQUEST);
        } catch (PaymentNotFoundException|PaymentGatewayTimeoutException|PaymentGatewayException) {
            // The notification may have arrived before the provider resource
            // is queryable, or the provider may be down. Ask for a retry.
            return $this->response('retry', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $record = $this->paymentService->processWebhook($event);

        return $this->response(strtolower($record->status->value), Response::HTTP_OK);
    }

    private function response(string $status, int $httpStatus): JsonResponse
    {
        // Never echo the notification, payer data or provider errors.
        return response()->json(['status' => $status], $httpStatus);
    }
}
