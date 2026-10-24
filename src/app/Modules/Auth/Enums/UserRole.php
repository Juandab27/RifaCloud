<?php

namespace App\Modules\Auth\Enums;

enum UserRole: string
{
    case USER = 'USER';
    case ADMIN = 'ADMIN';
}
