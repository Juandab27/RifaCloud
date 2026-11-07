<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService;

final class Application
{
    public function __construct(private readonly RaffleRepository $raffles) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public function handle(string $method, string $path, array $query, string $baseUrl): JsonResponse
    {
        $path = '/'.trim($path, '/');

        if ($method !== 'GET') {
            return new JsonResponse(
                ['message' => 'Method Not Allowed'],
                405,
                ['Allow' => 'GET'],
            );
        }

        if ($path === '/api/raffles') {
            return $this->catalog($query, $baseUrl);
        }

        if (preg_match('#^/api/raffles/([1-9][0-9]*)$#', $path, $matches) === 1) {
            $raffle = $this->raffles->findPublished((int) $matches[1]);

            return $raffle === null
                ? new JsonResponse(['message' => 'Not Found'], 404)
                : new JsonResponse(['data' => $raffle]);
        }

        if (preg_match('#^/api/raffles/([1-9][0-9]*)/numbers$#', $path, $matches) === 1) {
            $page = max(1, (int) ($query['page'] ?? 1));
            $perPage = (int) ($query['per_page'] ?? 100);
            $numbers = $this->raffles->numbersForPublishedRaffle((int) $matches[1], $page, $perPage);

            return $numbers === null
                ? new JsonResponse(['message' => 'Not Found'], 404)
                : new JsonResponse($numbers);
        }

        return new JsonResponse(['message' => 'Not Found'], 404);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function catalog(array $query, string $baseUrl): JsonResponse
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $result = $this->raffles->paginatePublished($page);
        $path = rtrim($baseUrl, '/').'/api/raffles';
        $count = count($result['data']);

        $links = [
            'first' => $path.'?page=1',
            'last' => $path.'?page='.$result['last_page'],
            'prev' => $result['page'] > 1 ? $path.'?page='.($result['page'] - 1) : null,
            'next' => $result['page'] < $result['last_page'] ? $path.'?page='.($result['page'] + 1) : null,
        ];

        return new JsonResponse([
            'data' => $result['data'],
            'links' => $links,
            'meta' => [
                'current_page' => $result['page'],
                'from' => $count > 0 ? (($result['page'] - 1) * $result['per_page']) + 1 : null,
                'last_page' => $result['last_page'],
                'links' => $this->paginationLinks($path, $result['page'], $result['last_page']),
                'path' => $path,
                'per_page' => $result['per_page'],
                'to' => $count > 0 ? min($result['page'] * $result['per_page'], $result['total']) : null,
                'total' => $result['total'],
            ],
        ]);
    }

    /**
     * Small window compatible with Laravel's LengthAwarePaginator shape.
     *
     * @return list<array{url: string|null, label: string, page: int|null, active: bool}>
     */
    private function paginationLinks(string $path, int $page, int $lastPage): array
    {
        $links = [];

        if ($page > 1) {
            $links[] = [
                'url' => $path.'?page='.($page - 1),
                'label' => '&laquo; Previous',
                'page' => $page - 1,
                'active' => false,
            ];
        }

        $start = max(1, $page - 2);
        $end = min($lastPage, $page + 2);

        if ($start > 1) {
            $links[] = ['url' => $path.'?page=1', 'label' => '1', 'page' => 1, 'active' => $page === 1];

            if ($start > 2) {
                $links[] = ['url' => null, 'label' => '...', 'page' => null, 'active' => false];
            }
        }

        for ($current = $start; $current <= $end; $current++) {
            $links[] = [
                'url' => $path.'?page='.$current,
                'label' => (string) $current,
                'page' => $current,
                'active' => $current === $page,
            ];
        }

        if ($end < $lastPage) {
            if ($end < $lastPage - 1) {
                $links[] = ['url' => null, 'label' => '...', 'page' => null, 'active' => false];
            }

            $links[] = [
                'url' => $path.'?page='.$lastPage,
                'label' => (string) $lastPage,
                'page' => $lastPage,
                'active' => $page === $lastPage,
            ];
        }

        if ($page < $lastPage) {
            $links[] = [
                'url' => $path.'?page='.($page + 1),
                'label' => 'Next &raquo;',
                'page' => $page + 1,
                'active' => false,
            ];
        }

        return $links;
    }
}
