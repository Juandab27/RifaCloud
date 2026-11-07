<?php

declare(strict_types=1);

namespace RifaCloud\OrdersService\Orders;

use RuntimeException;

/**
 * Laravel-compatible 422 payload: message (first error) plus an errors map.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        $first = '';

        foreach ($errors as $messages) {
            if ($messages !== []) {
                $first = $messages[0];
                break;
            }
        }

        parent::__construct($first === '' ? 'The given data was invalid.' : $first);
    }

    /**
     * @param  list<string>  $messages
     */
    public static function field(string $field, string $message): self
    {
        return new self([$field => [$message]]);
    }
}
