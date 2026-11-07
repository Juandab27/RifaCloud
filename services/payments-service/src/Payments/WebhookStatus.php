<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

enum WebhookStatus: string
{
    case RECEIVED = 'RECEIVED';
    case PROCESSED = 'PROCESSED';
    case FAILED = 'FAILED';
    case IGNORED = 'IGNORED';
}
