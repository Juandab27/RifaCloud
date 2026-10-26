<?php

namespace App\Console\Commands;

use App\Modules\Orders\Services\ReservationExpiryService;
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    /**
     * @var string
     */
    protected $signature = 'orders:expire-reservations';

    /**
     * @var string
     */
    protected $description = 'Release numbers held by expired PENDING_PAYMENT orders';

    public function handle(ReservationExpiryService $service): int
    {
        $expired = $service->expire();

        $this->info("Expired orders: {$expired}");

        return self::SUCCESS;
    }
}
