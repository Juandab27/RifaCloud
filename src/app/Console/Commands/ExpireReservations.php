<?php

namespace App\Console\Commands;

use App\Modules\Orders\Contracts\ReservationExpiryClient;
use Illuminate\Console\Command;
use Throwable;

class ExpireReservations extends Command
{
    /**
     * @var string
     */
    protected $signature = 'orders:expire-reservations';

    /**
     * @var string
     */
    protected $description = 'Ask orders-service to release numbers held by expired PENDING_PAYMENT orders';

    /**
     * S3 moved reservation expiry to orders-service. The monolith only sends
     * the signed internal command, so the advisory lock and the final
     * order/payment/number checks run in exactly one owner.
     *
     * When the service is unavailable the command fails closed; the scheduler
     * retries on the next minute and no numbers are released locally.
     */
    public function handle(ReservationExpiryClient $client): int
    {
        try {
            $expired = $client->expire();
        } catch (Throwable $exception) {
            $this->error('Reservation expiry failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Expired orders: {$expired}");

        return self::SUCCESS;
    }
}
