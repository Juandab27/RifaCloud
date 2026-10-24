<?php

namespace App\Modules\Payments\Enums;

enum PaymentProvider: string
{
    case MERCADOPAGO = 'MERCADOPAGO';

    /**
     * Reserved in the data model, not implemented in the MVP (Q-07).
     */
    case PAYPAL = 'PAYPAL';
}
