<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use PDO;
use PDOException;
use DateTimeImmutable;
use DateTimeZone;
use RifaCloud\PaymentsService\Auth\AuthException;
use RifaCloud\PaymentsService\Auth\CsrfTokenMismatchException;
use RifaCloud\PaymentsService\Auth\UserSessionAuthenticator;
use RifaCloud\PaymentsService\JsonResponse;
use Throwable;

/**
 * HTTP boundary for the S4 payment routes.
 *
 * Route matching happens before authentication so unknown paths return 404 and
 * unsupported methods 405. CSRF runs before authentication for stateful
 * requests (419 before 401), matching Sanctum's middleware order. The webhook
 * and the internal reconcile command never use the session.
 */
final class PaymentApplication
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly UserSessionAuthenticator $authenticator,
        private readonly CheckoutService $checkout,
        private readonly VerificationService $verification,
        private readonly WebhookService $webhook,
        private readonly OrderProjection $projection,
        private readonly InternalRequestVerifier $internalVerifier,
        private readonly PaymentRepository $payments,
        private readonly int $reconcileLimit,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $cookies
     */
    public function handle(
        string $method,
        string $path,
        array $query,
        array $headers,
        array $cookies,
        string $rawBody,
        string $baseUrl,
    ): JsonResponse {
        try {
            return $this->dispatch($method, $path, $query, $headers, $cookies, $rawBody, $baseUrl);
        } catch (AuthException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], $exception->status);
        } catch (CsrfTokenMismatchException) {
            return new JsonResponse(['message' => 'CSRF token mismatch.'], 419);
        } catch (NotFoundException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 404);
        } catch (PaymentConflictException $exception) {
            return new JsonResponse([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
                ...$exception->context,
            ], 409);
        } catch (PaymentGatewayTimeoutException) {
            return new JsonResponse([
                'message' => 'El proveedor de pagos no respondió. Inténtalo de nuevo.',
                'code' => 'PAYMENT_PROVIDER_TIMEOUT',
            ], 503);
        } catch (PaymentGatewayException) {
            return new JsonResponse([
                'message' => 'No se pudo iniciar el pago con el proveedor. Inténtalo de nuevo.',
                'code' => 'PAYMENT_PROVIDER_ERROR',
            ], 502);
        } catch (OrdersUnavailableException $exception) {
            error_log("[payments-service] orders-service unavailable: {$exception->getMessage()}\n");

            return new JsonResponse([
                'message' => 'No se pudo confirmar el pago en este momento. Inténtalo de nuevo.',
                'code' => 'ORDER_SERVICE_UNAVAILABLE',
            ], 503);
        } catch (MalformedJsonException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        } catch (ServiceUnavailableException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 503);
        } catch (PDOException $exception) {
            error_log(sprintf(
                "[payments-service] database error: %s\n",
                (string) $exception->getCode(),
            ));

            return new JsonResponse(['message' => 'Payments temporarily unavailable.'], 503);
        } catch (Throwable $exception) {
            error_log(sprintf(
                "[payments-service] unexpected error: %s: %s\n",
                $exception::class,
                $exception->getMessage(),
            ));

            return new JsonResponse(['message' => 'Internal Server Error'], 500);
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $cookies
     */
    private function dispatch(
        string $method,
        string $path,
        array $query,
        array $headers,
        array $cookies,
        string $rawBody,
        string $baseUrl,
    ): JsonResponse {
        $path = '/'.trim($path, '/');

        if ($path === '/health') {
            if ($method !== 'GET') {
                return new JsonResponse(['message' => 'Method Not Allowed'], 405, ['Allow' => 'GET']);
            }

            $this->pdo->query('SELECT 1');

            return new JsonResponse(['status' => 'ok']);
        }

        if ($path === '/api/webhooks/mercadopago') {
            if ($method !== 'POST') {
                return new JsonResponse(['message' => 'Method Not Allowed'], 405, ['Allow' => 'POST']);
            }

            return $this->webhook($query, $headers, $rawBody);
        }

        if ($path === '/internal/payments/reconcile') {
            if ($method !== 'POST') {
                return new JsonResponse(['message' => 'Method Not Allowed'], 405, ['Allow' => 'POST']);
            }

            $this->internalVerifier->assertValid($headers, $rawBody);

            return $this->reconcile($this->decodeBody($rawBody));
        }

        if (preg_match('#^/api/orders/([1-9][0-9]*)/payments$#', $path, $matches) === 1) {
            if ($method !== 'POST') {
                return new JsonResponse(['message' => 'Method Not Allowed'], 405, ['Allow' => 'POST']);
            }

            return $this->checkout((int) $matches[1], $headers, $cookies, $rawBody, $baseUrl);
        }

        if (preg_match('#^/api/orders/([1-9][0-9]*)/payments/([1-9][0-9]*)/verify$#', $path, $matches) === 1) {
            if ($method !== 'POST') {
                return new JsonResponse(['message' => 'Method Not Allowed'], 405, ['Allow' => 'POST']);
            }

            return $this->verify((int) $matches[1], (int) $matches[2], $headers, $cookies, $rawBody, $baseUrl);
        }

        if (preg_match('#^/api/admin/orders/([1-9][0-9]*)/payments/([1-9][0-9]*)/resolve$#', $path, $matches) === 1) {
            if ($method !== 'POST') {
                return new JsonResponse(['message' => 'Method Not Allowed'], 405, ['Allow' => 'POST']);
            }

            return $this->resolve((int) $matches[1], (int) $matches[2], $headers, $cookies, $rawBody, $baseUrl);
        }

        if ($path === '/api/admin/payments/incidents') {
            if ($method !== 'GET') {
                return new JsonResponse(['message' => 'Method Not Allowed'], 405, ['Allow' => 'GET']);
            }

            return $this->incidents($query, $headers, $cookies);
        }

        return new JsonResponse(['message' => 'Not Found'], 404);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $cookies
     */
    private function checkout(
        int $orderId,
        array $headers,
        array $cookies,
        string $rawBody,
        string $baseUrl,
    ): JsonResponse {
        $body = $this->decodeBody($rawBody);
        $user = $this->authenticate($headers, $cookies, $body, $baseUrl);

        $result = $this->checkout->create($user->userId, $orderId);

        return new JsonResponse(
            $this->projection->present($orderId),
            $result['created'] ? 201 : 200,
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $cookies
     */
    private function verify(
        int $orderId,
        int $paymentId,
        array $headers,
        array $cookies,
        string $rawBody,
        string $baseUrl,
    ): JsonResponse {
        $body = $this->decodeBody($rawBody);
        $user = $this->authenticate($headers, $cookies, $body, $baseUrl);

        $this->verification->verify($user->userId, $orderId, $paymentId);

        return new JsonResponse($this->projection->present($orderId));
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $cookies
     */
    private function resolve(
        int $orderId,
        int $paymentId,
        array $headers,
        array $cookies,
        string $rawBody,
        string $baseUrl,
    ): JsonResponse {
        $body = $this->decodeBody($rawBody);

        $context = $this->authenticator->load($cookies);

        if ($this->authenticator->isStatefulRequest($headers, $baseUrl)) {
            $this->authenticator->assertCsrf('POST', $headers, $body, $context);
        }

        $this->authenticator->requireAdmin($context);

        $this->verification->resolve($orderId, $paymentId);

        return new JsonResponse($this->projection->present($orderId));
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $cookies
     */
    private function incidents(array $query, array $headers, array $cookies): JsonResponse
    {
        $context = $this->authenticator->load($cookies);
        $this->authenticator->assertCsrf('GET', $headers, [], $context);
        $this->authenticator->requireAdmin($context);

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($query['per_page'] ?? 15)));
        $status = strtoupper(trim((string) ($query['status'] ?? '')));

        if ($status !== '' && ! in_array($status, ['UNKNOWN', 'REFUND_REQUIRED'], true)) {
            return new JsonResponse([
                'message' => 'The selected payment status is invalid.',
                'errors' => ['status' => ['The selected payment status is invalid.']],
            ], 422);
        }

        $total = $this->payments->countIncidents($status === '' ? null : $status);
        $rows = $this->payments->listIncidents(
            $status === '' ? null : $status,
            $perPage,
            ($page - 1) * $perPage,
        );

        $data = array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'order_id' => (int) $row['order_id'],
                'order_number' => (string) $row['order_number'],
                'status' => (string) $row['status'],
                'amount' => (int) $row['amount'],
                'currency' => (string) $row['currency'],
                'created_at' => self::iso($row['created_at']),
                'verified_at' => self::iso($row['verified_at']),
                'customer' => [
                    'id' => (int) $row['user_id'],
                    'name' => (string) $row['customer_name'],
                    'email' => (string) $row['customer_email'],
                ],
                'raffle' => [
                    'id' => (int) $row['raffle_id'],
                    'title' => (string) $row['raffle_title'],
                ],
            ],
            $rows,
        );

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
            ],
        ]);
    }

    private static function iso(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('c');
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    private function webhook(array $query, array $headers, string $rawBody): JsonResponse
    {
        try {
            $payload = $rawBody === '' ? null : json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $payload = null;
        }

        if (! is_array($payload) || $payload === []) {
            return new JsonResponse(['status' => 'invalid_payload'], 400);
        }

        if (! isset($payload['data']['id']) && isset($query['data.id'])) {
            $payload['data'] = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $payload['data']['id'] = $query['data.id'];
        }

        if (! isset($payload['type']) && isset($query['type'])) {
            $payload['type'] = $query['type'];
        }

        try {
            $status = $this->webhook->handle($payload, [
                'x-signature' => (string) ($headers['x-signature'] ?? ''),
                'x-request-id' => (string) ($headers['x-request-id'] ?? ''),
            ]);
        } catch (InvalidWebhookSignatureException) {
            return new JsonResponse(['status' => 'invalid_signature'], 401);
        } catch (InvalidWebhookPayloadException) {
            return new JsonResponse(['status' => 'invalid_payload'], 400);
        } catch (PaymentNotFoundException|PaymentGatewayTimeoutException|PaymentGatewayException) {
            return new JsonResponse(['status' => 'retry'], 503);
        } catch (Throwable $exception) {
            error_log(sprintf(
                "[payments-service] webhook processing failed: %s\n",
                $exception::class,
            ));

            // The event is already marked FAILED when possible; asking for a
            // retry is safe because the command and the event are idempotent.
            return new JsonResponse(['status' => 'retry'], 503);
        }

        return new JsonResponse(['status' => strtolower($status->value)], 200);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function reconcile(array $body): JsonResponse
    {
        $limit = (int) ($body['limit'] ?? $this->reconcileLimit);
        $dryRun = (bool) ($body['dry_run'] ?? false);
        $approved = (bool) ($body['approved'] ?? false);

        if ($approved) {
            $result = $this->verification->reconcileApproved($limit, $dryRun);

            return new JsonResponse(['mode' => 'approved', ...$result], 200);
        }

        return new JsonResponse([
            'mode' => 'reconcile',
            'dry_run' => $dryRun,
            'count' => $this->verification->reconcile($limit, $dryRun),
        ], 200);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $body
     */
    private function authenticate(
        array $headers,
        array $cookies,
        array $body,
        string $baseUrl,
    ): \RifaCloud\PaymentsService\Auth\UserSessionContext {
        $context = $this->authenticator->load($cookies);

        if ($this->authenticator->isStatefulRequest($headers, $baseUrl)) {
            $this->authenticator->assertCsrf('POST', $headers, $body, $context);
        }

        return $this->authenticator->requireUser($context);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(string $rawBody): array
    {
        if (trim($rawBody) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MalformedJsonException();
        }

        if (! is_array($decoded)) {
            throw new MalformedJsonException();
        }

        return $decoded;
    }
}
