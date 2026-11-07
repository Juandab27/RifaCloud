<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Auth;

use RuntimeException;

final class CsrfTokenMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('CSRF token mismatch.');
    }
}
