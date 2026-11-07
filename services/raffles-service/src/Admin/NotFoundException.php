<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Admin;

use RuntimeException;

final class NotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Not Found');
    }
}
