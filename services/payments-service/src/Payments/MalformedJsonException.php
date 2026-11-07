<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

use RuntimeException;

final class MalformedJsonException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Malformed JSON.');
    }
}
