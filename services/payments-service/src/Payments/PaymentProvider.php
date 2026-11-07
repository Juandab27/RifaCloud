<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService\Payments;

enum PaymentProvider: string
{
    case MERCADOPAGO = 'MERCADOPAGO';
    case PAYPAL = 'PAYPAL';
}
