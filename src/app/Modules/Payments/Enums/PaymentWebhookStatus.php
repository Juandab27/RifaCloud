<?php

namespace App\Modules\Payments\Enums;

enum PaymentWebhookStatus: string
{
    case RECEIVED = 'RECEIVED';
    case PROCESSED = 'PROCESSED';
    case FAILED = 'FAILED';
    case IGNORED = 'IGNORED';
}
