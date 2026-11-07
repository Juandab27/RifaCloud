<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Admin;

use RuntimeException;

final class CsrfTokenMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('CSRF token mismatch.');
    }
}
