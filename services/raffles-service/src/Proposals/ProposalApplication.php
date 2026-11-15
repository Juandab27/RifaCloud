<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Proposals;

use RifaCloud\RafflesService\Admin\AdminAuthException;
use RifaCloud\RafflesService\Admin\CsrfTokenMismatchException;
use RifaCloud\RafflesService\Admin\MalformedJsonException;
use RifaCloud\RafflesService\Admin\NotFoundException;
use RifaCloud\RafflesService\Admin\ValidationException;
use RifaCloud\RafflesService\JsonResponse;
use Throwable;

/**
 * Public, authenticated proposal submission.
 *
 * The proposer identity and contact always come from the shared session and
 * the users row. Any `user_id`, name, email or phone in the body is ignored.
 */
final class ProposalApplication
{
    public function __construct(
        private readonly RaffleProposalService $proposals,
        private readonly ProposalUserAuthenticator $authenticator,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $cookies
     */
    public function handle(
        string $method,
        string $path,
        array $headers,
        array $cookies,
        string $rawBody,
    ): JsonResponse {
        $path = '/'.trim($path, '/');

        try {
            if ($path !== '/api/proposals') {
                return new JsonResponse(['message' => 'Not Found'], 404);
            }

            if ($method !== 'POST') {
                return new JsonResponse(
                    ['message' => 'Method Not Allowed'],
                    405,
                    ['Allow' => 'POST'],
                );
            }

            $body = $this->parseBody($rawBody, $headers);
            $user = $this->authenticator->authenticate($method, $headers, $cookies, $body);

            return new JsonResponse(['data' => $this->proposals->create($body, $user)], 201);
        } catch (AdminAuthException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], $exception->status);
        } catch (CsrfTokenMismatchException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 419);
        } catch (ValidationException $exception) {
            return new JsonResponse([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors,
            ], 422);
        } catch (ProposalRateLimitException $exception) {
            return new JsonResponse(
                ['message' => $exception->getMessage()],
                429,
                ['Retry-After' => (string) max(1, $exception->retryAfterSeconds)],
            );
        } catch (NotFoundException) {
            return new JsonResponse(['message' => 'Not Found'], 404);
        } catch (MalformedJsonException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        } catch (Throwable $exception) {
            error_log(sprintf(
                '[raffles-service] proposal submission failed: %s: %s',
                $exception::class,
                $exception->getMessage(),
            ));

            return new JsonResponse(
                ['message' => 'No se pudo registrar la propuesta en este momento.'],
                500,
            );
        }
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
}
