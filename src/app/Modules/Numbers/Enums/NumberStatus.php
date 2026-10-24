<?php

namespace App\Modules\Numbers\Enums;

enum NumberStatus: string
{
    case AVAILABLE = 'AVAILABLE';
    case RESERVED = 'RESERVED';
    case SOLD = 'SOLD';
}
