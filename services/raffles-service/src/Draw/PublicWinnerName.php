<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Draw;

/**
 * Builds the only winner name allowed in public responses.
 *
 * The full name, email, phone, document and internal ids never leave the
 * administrative side. The stored value is a snapshot, so later profile
 * changes do not rewrite a published result.
 */
final class PublicWinnerName
{
    public static function mask(string $name): ?string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        $normalized = trim($normalized);

        if ($normalized === '') {
            return null;
        }

        $parts = explode(' ', $normalized);
        $first = $parts[0];

        if (count($parts) === 1) {
            return mb_substr($first, 0, 1).'***';
        }

        $lastInitial = mb_substr($parts[count($parts) - 1], 0, 1);

        if (mb_strlen($first) <= 2) {
            $first = mb_substr($first, 0, 1).'***';
        }

        return mb_substr($first.' '.$lastInitial.'.', 0, 120);
    }
}
