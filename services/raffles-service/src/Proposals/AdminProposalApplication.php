<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Proposals;

use RifaCloud\RafflesService\Admin\AdminAuthException;
use RifaCloud\RafflesService\Admin\AdminSession;
use RifaCloud\RafflesService\Admin\AdminSessionAuthenticator;
use RifaCloud\RafflesService\Admin\CsrfTokenMismatchException;
use RifaCloud\RafflesService\Admin\MalformedJsonException;
use RifaCloud\RafflesService\Admin\NotFoundException;
use RifaCloud\RafflesService\Admin\Pagination;
use RifaCloud\RafflesService\Admin\ValidationException;
use RifaCloud\RafflesService\JsonResponse;

/**
 * ADMIN-only moderation boundary for /api/admin/proposals*.
 *
 * Contact details are returned only here, never by a public endpoint.
 * Approval does not create a raffle or any other business record.
 */
final class AdminProposalApplication
{
    public function __construct(
        private readonly RaffleProposalService $proposals,
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
            if ($path === '/api/admin/proposals') {
                if ($method !== 'GET') {
                    return $this->methodNotAllowed(['GET']);
                }

                $this->authorize($method, $headers, $cookies, []);
                $status = isset($query['status']) && $query['status'] !== ''
                    ? (string) $query['status']
                    : null;
                $page = max(1, (int) ($query['page'] ?? 1));
                $result = $this->proposals->paginate($page, $status);

                return new JsonResponse(
                    Pagination::envelope(
                        $result,
                        rtrim($baseUrl, '/').'/api/admin/proposals',
                    ),
                );
            }

            if ($path === '/api/admin/proposals/metrics') {
                if ($method !== 'GET') {
                    return $this->methodNotAllowed(['GET']);
                }

                $this->authorize($method, $headers, $cookies, []);

                return new JsonResponse(['data' => $this->proposals->metrics()]);
            }

            if (preg_match('#^/api/admin/proposals/([0-9]+)$#', $path, $matches) === 1) {
                if ($method !== 'GET') {
                    return $this->methodNotAllowed(['GET']);
                }

                $this->authorize($method, $headers, $cookies, []);

                return new JsonResponse(['data' => $this->proposals->show((int) $matches[1])]);
            }

            if (
                preg_match(
                    '#^/api/admin/proposals/([0-9]+)/(approve|reject)$#',
                    $path,
                    $matches,
                ) === 1
            ) {
                if ($method !== 'POST') {
                    return $this->methodNotAllowed(['POST']);
                }

                $body = $this->parseBody($rawBody, $headers);
                $admin = $this->authorize($method, $headers, $cookies, $body);

                return new JsonResponse([
                    'data' => $this->proposals->decide(
                        (int) $matches[1],
                        $matches[2] === 'approve' ? 'APPROVED' : 'REJECTED',
                        $body,
                        $admin,
                    ),
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
        } catch (ProposalConflictException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 409);
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
