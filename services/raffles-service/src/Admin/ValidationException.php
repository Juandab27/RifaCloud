<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Admin;

use RuntimeException;

/**
 * Mirrors the JSON payload Laravel returns for a failed FormRequest.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        $messages = [];

        foreach ($errors as $fieldMessages) {
            foreach ($fieldMessages as $message) {
                $messages[] = $message;
            }
        }

        $message = $messages[0] ?? 'The given data was invalid.';

        if (count($messages) > 1) {
            $message .= ' (and '.(count($messages) - 1).' more errors)';
        }

        parent::__construct($message);
    }

    public static function single(string $field, string $message): self
    {
        return new self([$field => [$message]]);
    }
}
