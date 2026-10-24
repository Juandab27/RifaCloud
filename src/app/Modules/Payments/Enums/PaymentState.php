<?php

namespace App\Modules\Payments\Enums;

enum PaymentState: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case UNKNOWN = 'UNKNOWN';
    case REFUND_REQUIRED = 'REFUND_REQUIRED';
}
