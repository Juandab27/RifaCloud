<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Orders;

use PDO;
use PDOException;
use RifaCloud\OrdersService\Auth\AuthException;
use RifaCloud\OrdersService\Auth\CsrfTokenMismatchException;
use RifaCloud\OrdersService\Auth\UserSessionAuthenticator;
use RifaCloud\OrdersService\JsonResponse;
use Throwable;

/**
 * HTTP boundary for the S3 order routes.
 *
 * Route matching happens before authentication so unknown paths still return
 * 404 and unsupported methods return 405, exactly like Laravel's router.
 * CSRF runs before authentication for stateful requests, matching Sanctum's
 * middleware order (419 before 401 on unsafe browser requests).
 */
final class OrderApplication
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly UserSessionAuthenticator $authenticator,
        private readonly OrderReservationService $reservations,
        private readonly OrderRepository $orders,
        private readonly OrderPresenter $presenter,
        private readonly ReservationExpiryService $expiry,
        private readonly InternalRequestVerifier $internalVerifier,
        private readonly PaymentResultApplier $paymentResults,
        private readonly InternalRequestVerifier $paymentsVerifier,
        private readonly int $maxNumbersPerOrder,
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
        } catch (ValidationException $exception) {
            return new JsonResponse([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors,
            ], 422);
        } catch (OrderConflictException $exception) {
            return new JsonResponse([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
                ...$exception->context,
            ], 409);
        } catch (PaymentConflictException $exception) {
            return new JsonResponse([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
                ...$exception->context,
            ], 409);
        } catch (ForbiddenException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 403);
        } catch (NotFoundException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 404);
        } catch (MalformedJsonException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        } catch (ServiceUnavailableException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 503);
        } catch (PDOException $exception) {
            error_log(sprintf(
                "[orders-service] database error: %s\n",
                (string) $exception->getCode(),
            ));

            return new JsonResponse(['message' => 'Orders temporarily unavailable.'], 503);
        } catch (Throwable $exception) {
            error_log(sprintf(
                "[orders-service] unexpected error: %s: %s\n",
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

        if ($path === '/api/admin/orders/metrics') {
            if ($method !== 'GET') {
                return new JsonResponse(['message' => 'Method Not Allowed'], 405, ['Allow' => 'GET']);
            }

            return $this->adminMetrics($headers, $cookies);
        }

        if ($path === '/api/admin/orders') {
            if ($method !== 'GET') {
                return new JsonResponse(['message' => 'Method Not Allowed'], 405, ['Allow' => 'GET']);
            }

            return $this->adminList($query, $headers, $cookies);
        }

        if ($path === '/api/orders') {
            if ($method === 'POST') {
                return $this->create($headers, $cookies, $rawBody, $baseUrl);
            }

            if ($method === 'GET') {
                return $this->list($query, $cookies);
            }

            return new JsonResponse(['message' => 'Method Not Allowed'], 405, ['Allow' => 'GET, POST']);
        }

        if (preg_match('#^/api/orders/([1-9][0-9]*)$#', $path, $matches) === 1) {
            if ($method !== 'GET') {
                return new JsonResponse(['message' => 'Method Not Allowed'], 405, ['Allow' => 'GET']);
            }

            return $this->show((int) $matches[1], $headers, $cookies);
        }

        if ($path === '/internal/orders/expire-reservations') {
            if ($method !== 'POST') {
                return new JsonResponse(['message' => 'Method Not Allowed'], 405, ['Allow' => 'POST']);
            }

            $this->internalVerifier->assertValid($headers, $rawBody);
            $result = $this->expiry->expireWithLock();

            return new JsonResponse($result);
        }

        if ($path === '/internal/orders/payment-result') {
            if ($method !== 'POST') {
                return new JsonResponse(['message' => 'Method Not Allowed'], 405, ['Allow' => 'POST']);
            }

            // Authenticated before parsing: an unsigned caller must not reach
            // the state machine or even learn whether the body is valid.
            $this->paymentsVerifier->assertValid($headers, $rawBody);

            return new JsonResponse(
                $this->paymentResults->apply($this->decodeBody($rawBody)),
            );
        }

        return new JsonResponse(['message' => 'Not Found'], 404);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $cookies
     */
    private function create(
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

        $user = $this->authenticator->requireUser($context);
        $validated = $this->validateCreate($body);

        $orderId = $this->reservations->create(
            $user->userId,
            $validated['raffle_id'],
            $validated['numbers'],
        );

        return $this->orderResponse($orderId, $user->userId, 201);
    }

    /**
     * Paginated administrative order list. Every order is visible, but only
     * the role loaded from auth-service can reach it.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $cookies
     */
    private function adminList(array $query, array $headers, array $cookies): JsonResponse
    {
        $context = $this->authenticator->load($cookies);
        $this->authenticator->assertCsrf('GET', $headers, [], $context);
        $this->authenticator->requireAdmin($context);

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($query['per_page'] ?? 15)));
        $status = strtoupper(trim((string) ($query['status'] ?? '')));
        $allowed = [
            'PENDING_PAYMENT',
            'PAID',
            'REJECTED',
            'EXPIRED',
            'PAYMENT_REVIEW',
            'REQUIRES_REFUND',
        ];

        if ($status !== '' && ! in_array($status, $allowed, true)) {
            throw ValidationException::field('status', 'The selected status is invalid.');
        }

        $total = $this->orders->countAll($status === '' ? null : $status);
        $orders = $this->orders->listAll(
            $status === '' ? null : $status,
            $perPage,
            ($page - 1) * $perPage,
        );
        $orderIds = array_map(
            static fn (array $order): int => (int) $order['id'],
            $orders,
        );

        return new JsonResponse(
            $this->presenter->presentAdminCollection(
                $orders,
                $this->orders->numbersForOrders($orderIds),
                $this->orders->paymentsForOrders($orderIds),
                $page,
                $perPage,
                $total,
            ),
        );
    }

    /**
     * Read-only dashboard aggregates. PAID is the only confirmed-sale order
     * state; pending, rejected, expired and refund states are never counted.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $cookies
     */
    private function adminMetrics(array $headers, array $cookies): JsonResponse
    {
        $context = $this->authenticator->load($cookies);
        $this->authenticator->assertCsrf('GET', $headers, [], $context);
        $this->authenticator->requireAdmin($context);

        return new JsonResponse([
            'data' => [
                'paid_orders' => $this->orders->countAll('PAID'),
                'sold_numbers' => $this->orders->countSoldNumbers(),
            ],
        ]);
    }

    /**
     * Authenticated "My purchases" list, newest first.
     *
     * The owner filter is part of the SQL query, so another user's orders can
     * never appear even if a caller tampers with page/per_page.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $cookies
     */
    private function list(array $query, array $cookies): JsonResponse    {
        $context = $this->authenticator->load($cookies);
        $user = $this->authenticator->requireUser($context);

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($query['per_page'] ?? 10)));

        $total = $this->orders->countForUser($user->userId);
        $orders = $this->orders->listForUser($user->userId, $perPage, ($page - 1) * $perPage);
        $orderIds = array_map(
            static fn (array $order): int => (int) $order['id'],
            $orders,
        );

        return new JsonResponse(
            $this->presenter->presentCollection(
                $orders,
                $this->orders->numbersForOrders($orderIds),
                $this->orders->paymentsForOrders($orderIds),
                $page,
                $perPage,
                $total,
            ),
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $cookies
     */
    private function show(int $orderId, array $headers, array $cookies): JsonResponse
    {
        $context = $this->authenticator->load($cookies);
        $user = $this->authenticator->requireUser($context);

        return $this->orderResponse($orderId, $user->userId, 200);
    }

    private function orderResponse(int $orderId, int $userId, int $status): JsonResponse
    {
        $record = $this->orders->findForUser($orderId, $userId);

        if ($record === null) {
            throw new NotFoundException();
        }

        return new JsonResponse($this->presenter->present($record), $status);
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

    /**
     * Mirrors StoreOrderRequest rules and Laravel's first-error 422 payload.
     *
     * @param  array<string, mixed>  $body
     * @return array{raffle_id: int, numbers: list<int>}
     */
    private function validateCreate(array $body): array
    {
        $errors = [];

        $raffleId = $body['raffle_id'] ?? null;

        if ($raffleId === null || $raffleId === '') {
            $errors['raffle_id'][] = 'The raffle id field is required.';
        } elseif (! $this->isInteger($raffleId)) {
            $errors['raffle_id'][] = 'The raffle id field must be an integer.';
        } else {
            $raffleId = (int) $raffleId;

            $statement = $this->pdo->prepare('SELECT 1 FROM raffles WHERE id = :id LIMIT 1');
            $statement->execute([':id' => $raffleId]);

            if ($statement->fetchColumn() === false) {
                $errors['raffle_id'][] = 'The selected raffle id is invalid.';
            }
        }

        $numbers = $body['numbers'] ?? null;
        $normalizedNumbers = [];

        if ($numbers === null) {
            $errors['numbers'][] = 'The numbers field is required.';
        } elseif (! is_array($numbers)) {
            $errors['numbers'][] = 'The numbers field must be an array.';
        } else {
            $count = count($numbers);

            if ($count < 1) {
                $errors['numbers'][] = 'The numbers field must have at least 1 items.';
            }

            if ($count > $this->maxNumbersPerOrder) {
                $errors['numbers'][] = sprintf(
                    'The numbers field must not have more than %d items.',
                    $this->maxNumbersPerOrder,
                );
            }

            $seen = [];

            foreach ($numbers as $key => $value) {
                $field = 'numbers.'.$key;

                if ($value === null || $value === '') {
                    $errors[$field][] = "The {$field} field is required.";

                    continue;
                }

                if (! $this->isInteger($value)) {
                    $errors[$field][] = "The {$field} field must be an integer.";

                    continue;
                }

                $number = (int) $value;

                if ($number < 1) {
                    $errors[$field][] = "The {$field} field must be at least 1.";

                    continue;
                }

                if ($number > 4294967295) {
                    $errors[$field][] = "The {$field} field must not be greater than 4294967295.";

                    continue;
                }

                if (isset($seen[$number])) {
                    $errors[$field][] = "The {$field} field has a duplicate value.";

                    continue;
                }

                $seen[$number] = true;
                $normalizedNumbers[] = $number;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        sort($normalizedNumbers);

        return [
            'raffle_id' => (int) $raffleId,
            'numbers' => $normalizedNumbers,
        ];
    }

    private function isInteger(mixed $value): bool
    {
        return is_int($value)
            || (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1);
    }
}
