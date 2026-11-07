<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Admin;

/**
 * Laravel LengthAwarePaginator-compatible envelope for admin lists.
 *
 * The React admin page consumes `data`, `links` and `meta` exactly as it did
 * from the monolith, so the pagination window is reproduced here.
 */
final class Pagination
{
    /**
     * @param  array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}  $result
     * @return array<string, mixed>
     */
    public static function envelope(array $result, string $path): array
    {
        $path = rtrim($path, '/');
        $count = count($result['data']);

        return [
            'data' => $result['data'],
            'links' => [
                'first' => $path.'?page=1',
                'last' => $path.'?page='.$result['last_page'],
                'prev' => $result['page'] > 1 ? $path.'?page='.($result['page'] - 1) : null,
                'next' => $result['page'] < $result['last_page'] ? $path.'?page='.($result['page'] + 1) : null,
            ],
            'meta' => [
                'current_page' => $result['page'],
                'from' => $count > 0 ? (($result['page'] - 1) * $result['per_page']) + 1 : null,
                'last_page' => $result['last_page'],
                'links' => self::links($path, $result['page'], $result['last_page']),
                'path' => $path,
                'per_page' => $result['per_page'],
                'to' => $count > 0 ? min($result['page'] * $result['per_page'], $result['total']) : null,
                'total' => $result['total'],
            ],
        ];
    }

    /**
     * @return list<array{url: string|null, label: string, page: int|null, active: bool}>
     */
    private static function links(string $path, int $page, int $lastPage): array
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
