<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Draw;

use RifaCloud\RafflesService\JsonResponse;
use Throwable;

/**
 * Internal scheduler boundary for raffles-service.
 *
 * Nginx blocks /internal/; the endpoint is reachable only inside the Compose
 * network and requires the HMAC signature derived from APP_KEY.
 */
final class InternalApplication
{
    public function __construct(
        private readonly RaffleDrawService $draws,
        private readonly InternalRequestVerifier $verifier,
    ) {}

    /**
     * @param  array<string, string>  $headers
     */
    public function handle(string $method, string $path, array $headers, string $rawBody): JsonResponse
    {
        $path = '/'.trim($path, '/');

        try {
            if ($path === '/internal/raffles/process-due') {
                if ($method !== 'POST') {
                    return new JsonResponse(['message' => 'Method Not Allowed'], 405, ['Allow' => 'POST']);
                }

                $this->verifier->assertValid($headers, $rawBody);

                $payload = json_decode($rawBody, true);
                $limit = is_array($payload)
                    && isset($payload['limit'])
                    && is_int($payload['limit'])
                        ? $payload['limit']
                        : null;

                return new JsonResponse($this->draws->processDue($limit));
            }

            return new JsonResponse(['message' => 'Not Found'], 404);
        } catch (InternalAuthException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], $exception->status);
        } catch (Throwable $exception) {
            error_log(sprintf(
                "[raffles-service] draw command failed: %s: %s\n",
                $exception::class,
                $exception->getMessage(),
            ));

            return new JsonResponse(['message' => 'Draw temporarily unavailable.'], 503);
        }
    }
}
