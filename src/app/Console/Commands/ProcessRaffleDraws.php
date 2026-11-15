<?php

namespace App\Console\Commands;

use App\Modules\Raffles\Contracts\RaffleDrawClient;
use Illuminate\Console\Command;
use Throwable;

class ProcessRaffleDraws extends Command
{
    /**
     * @var string
     */
    protected $signature = 'raffles:process-due
                            {--limit=20 : Maximum number of due automatic raffles to process}';

    /**
     * @var string
     */
    protected $description = 'Ask raffles-service to close due automatic sales and draw eligible winners';

    /**
     * The scheduler only sends the signed internal command. The raffle row
     * lock, candidate snapshot and unique result live in raffles-service.
     */
    public function handle(RaffleDrawClient $client): int
    {
        $limit = min(100, max(1, (int) $this->option('limit')));

        try {
            $result = $client->processDue($limit);
        } catch (Throwable $exception) {
            $this->error('Raffle draw processing failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Raffle draws: closed=%d drawn=%d blocked=%d skipped=%d errors=%d',
            $result['closed'],
            $result['drawn'],
            $result['blocked'],
            $result['skipped'],
            $result['errors'],
        ));

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
