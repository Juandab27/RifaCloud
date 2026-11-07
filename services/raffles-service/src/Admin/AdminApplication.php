<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Admin;

use RifaCloud\RafflesService\JsonResponse;

/**
 * HTTP boundary for /api/admin/raffles* (S2).
 *
 * Route matching happens before authentication so unknown paths still return
 * 404 and unsupported methods return 405, exactly like Laravel's router.
 * CSRF runs before the role check, matching Sanctum's stateful middleware
 * order (419 before 401/403 on unsafe requests).
 */
final class AdminApplication
{
    public function __construct(
        private readonly RaffleAdminService $raffles,
        private readonly AdminSessionAuthenticator $authenticator,
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
        $path = '/'.trim($path, '/');

        try {
            if ($path === '/api/admin/raffles') {
                if ($method === 'GET') {
                    $this->authorize($method, $headers, $cookies, []);
                    $page = max(1, (int) ($query['page'] ?? 1));
                    $result = $this->raffles->paginate($page);

                    return new JsonResponse(
                        Pagination::envelope(
                            $result,
                            rtrim($baseUrl, '/').'/api/admin/raffles',
                        ),
                    );
                }

                if ($method === 'POST') {
                    $body = $this->parseBody($rawBody, $headers);
                    $admin = $this->authorize($method, $headers, $cookies, $body);
                    $raffle = $this->raffles->create($body, $admin);

                    return new JsonResponse(['data' => $raffle], 201);
                }

                return $this->methodNotAllowed(['GET', 'POST']);
            }

            if (preg_match('#^/api/admin/raffles/([0-9]+)$#', $path, $matches) === 1) {
                $raffleId = (int) $matches[1];

                if ($method === 'GET') {
                    $this->authorize($method, $headers, $cookies, []);

                    return new JsonResponse(['data' => $this->raffles->show($raffleId)]);
                }

                if ($method === 'PUT') {
                    $body = $this->parseBody($rawBody, $headers);
                    $this->authorize($method, $headers, $cookies, $body);

                    return new JsonResponse(['data' => $this->raffles->update($raffleId, $body)]);
                }

                if ($method === 'DELETE') {
                    $body = $this->parseBody($rawBody, $headers);
                    $this->authorize($method, $headers, $cookies, $body);
                    $this->raffles->delete($raffleId);

                    return new JsonResponse([], 204);
                }

                return $this->methodNotAllowed(['GET', 'PUT', 'DELETE']);
            }

            if (
                preg_match('#^/api/admin/raffles/([0-9]+)/(publish|close)$#', $path, $matches) === 1
            ) {
                if ($method !== 'POST') {
                    return $this->methodNotAllowed(['POST']);
                }

                $body = $this->parseBody($rawBody, $headers);
                $this->authorize($method, $headers, $cookies, $body);
                $raffleId = (int) $matches[1];

                return new JsonResponse([
                    'data' => $matches[2] === 'publish'
                        ? $this->raffles->publish($raffleId)
                        : $this->raffles->close($raffleId),
                ]);
            }

            return new JsonResponse(['message' => 'Not Found'], 404);
        } catch (AdminAuthException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], $exception->status);
        } catch (CsrfTokenMismatchException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 419);
        } catch (ValidationException $exception) {
            return new JsonResponse([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors,
            ], 422);
        } catch (NotFoundException) {
            return new JsonResponse(['message' => 'Not Found'], 404);
        } catch (MalformedJsonException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $body
     *
     * @throws AdminAuthException
     * @throws CsrfTokenMismatchException
     */
    private function authorize(
        string $method,
        array $headers,
        array $cookies,
        array $body,
    ): AdminSession {
        $context = $this->authenticator->load($cookies);
        $this->authenticator->assertCsrf($method, $headers, $body, $context);

        return $this->authenticator->requireAdmin($context);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     *
     * @throws MalformedJsonException
     */
    private function parseBody(string $rawBody, array $headers): array
    {
        if (trim($rawBody) === '') {
            return [];
        }

        $contentType = strtolower($headers['content-type'] ?? '');

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                throw new MalformedJsonException();
            }

            return $decoded;
        }

        parse_str($rawBody, $parsed);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param  list<string>  $allowed
     */
    private function methodNotAllowed(array $allowed): JsonResponse
    {
        return new JsonResponse(
            ['message' => 'Method Not Allowed'],
            405,
            ['Allow' => implode(', ', $allowed)],
        );
    }
}
