<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Admin;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Server-side port of App\Modules\Raffles\Http\Requests\RaffleRequest.
 *
 * Rules and messages are kept identical to the Laravel FormRequest so the
 * React admin form keeps receiving the same 422 payload.
 */
final class RaffleValidator
{
    public function __construct(private readonly int $maxNumbers) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        $errors = [];
        $data = [];

        // title: required|string|max:255
        if (! array_key_exists('title', $input) || $this->isBlank($input['title'])) {
            $errors['title'][] = 'The title field is required.';
        } elseif (! is_string($input['title'])) {
            $errors['title'][] = 'The title field must be a string.';
        } elseif (mb_strlen($input['title']) > 255) {
            $errors['title'][] = 'The title field must not be greater than 255 characters.';
        } else {
            $data['title'] = $input['title'];
        }

        // description: nullable|string
        if (array_key_exists('description', $input)) {
            if ($input['description'] === null) {
                $data['description'] = null;
            } elseif (! is_string($input['description'])) {
                $errors['description'][] = 'The description field must be a string.';
            } else {
                $data['description'] = $input['description'];
            }
        }

        foreach (['price_per_number', 'min_number', 'max_number'] as $field) {
            $label = str_replace('_', ' ', $field);

            if (! array_key_exists($field, $input) || $this->isBlank($input[$field])) {
                $errors[$field][] = "The {$label} field is required.";

                continue;
            }

            if (! $this->isInteger($input[$field])) {
                $errors[$field][] = "The {$label} field must be an integer.";

                continue;
            }

            $value = (int) $input[$field];

            if ($value < 1) {
                $errors[$field][] = "The {$label} field must be at least 1.";

                continue;
            }

            if ($value > 4294967295) {
                $errors[$field][] = "The {$label} field must not be greater than 4294967295.";

                continue;
            }

            $data[$field] = $value;
        }

        if (
            isset($data['min_number'], $data['max_number'])
            && $data['max_number'] < $data['min_number']
        ) {
            $errors['max_number'][] = 'The max number field must be greater than or equal to min number.';
        }

        foreach (['starts_at', 'ends_at'] as $field) {
            $label = str_replace('_', ' ', $field);

            if (! array_key_exists($field, $input)) {
                continue;
            }

            if ($input[$field] === null) {
                $data[$field] = null;

                continue;
            }

            $normalized = $this->normalizeDate($input[$field]);

            if ($normalized === null) {
                $errors[$field][] = "The {$label} field must be a valid date.";

                continue;
            }

            $data[$field] = $normalized;
        }

        if (
            isset($data['ends_at'])
            && $data['ends_at'] !== null
            && isset($data['starts_at'])
            && $data['starts_at'] !== null
            && strtotime((string) $data['ends_at']) < strtotime((string) $data['starts_at'])
        ) {
            $errors['ends_at'][] = 'The ends at field must be a date after or equal to starts at.';
        }

        // Technical guard for number generation, not a business rule.
        if (
            isset($data['min_number'], $data['max_number'])
            && $data['max_number'] >= $data['min_number']
            && ($data['max_number'] - $data['min_number'] + 1) > $this->maxNumbers
        ) {
            $errors['max_number'][] = "The number range cannot exceed {$this->maxNumbers} numbers.";
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $data;
    }

    /**
     * Raffle::hasValidSchedule(): a stored end date must be strictly after
     * the start date. Missing dates add no restriction.
     *
     * @throws ValidationException
     */
    public function assertValidSchedule(?string $startsAt, ?string $endsAt): void
    {
        if ($startsAt === null || $endsAt === null || $startsAt === '' || $endsAt === '') {
            return;
        }

        if (strtotime($startsAt) >= strtotime($endsAt)) {
            throw ValidationException::single(
                'ends_at',
                'The end date must be after the start date.',
            );
        }
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function isInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        if (! is_string($value)) {
            return false;
        }

        $trimmed = trim($value);

        return $trimmed !== ''
            && preg_match('/^[+-]?[0-9]+$/', $trimmed) === 1
            && filter_var($trimmed, FILTER_VALIDATE_INT) !== false;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '' || strtotime($string) === false) {
            return null;
        }

        try {
            return (new DateTimeImmutable($string, new DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
