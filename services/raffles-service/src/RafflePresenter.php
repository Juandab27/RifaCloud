<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Single projection of a raffle row into the RaffleResource JSON contract.
 *
 * The public catalog, the admin list/show and the admin write responses must
 * all return exactly the same shape, so the mapping lives in one place.
 */
final class RafflePresenter
{
    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $result
     * @return array<string, mixed>
     */
    public static function present(
        array $row,
        int $reservationMinutes,
        int $maxNumbersPerOrder,
        ?array $result = null,
    ): array {
        $min = (int) $row['min_number'];
        $max = (int) $row['max_number'];

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'price_per_number' => (int) $row['price_per_number'],
            'min_number' => $min,
            'max_number' => $max,
            'total_numbers' => $max - $min + 1,
            'status' => (string) $row['status'],
            'reservation_minutes' => $reservationMinutes,
            'max_numbers_per_order' => $maxNumbersPerOrder,
            'starts_at' => self::iso8601($row['starts_at']),
            'ends_at' => self::iso8601($row['ends_at']),
            'numbers_count' => (int) ($row['numbers_count'] ?? 0),
            'result' => $result === null ? null : [
                'winning_number' => (int) $result['winning_number'],
                'published_at' => self::iso8601($result['published_at']),
                'evidence_description' => $result['evidence_description'] === null
                    ? null
                    : (string) $result['evidence_description'],
                'evidence_url' => $result['evidence_url'] === null
                    ? null
                    : (string) $result['evidence_url'],
            ],
            'created_at' => self::iso8601($row['created_at']),
            'updated_at' => self::iso8601($row['updated_at']),
        ];
    }

    public static function iso8601(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))
                ->format(DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
