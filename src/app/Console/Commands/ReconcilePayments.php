<?php

namespace App\Console\Commands;

use App\Modules\Payments\Contracts\PaymentsReconciliationClient;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePayments extends Command
{
    /**
     * @var string
     */
    protected $signature = 'payments:reconcile
                            {--limit=50 : Maximum number of payments to inspect}
                            {--dry-run : List how many payments would be reconciled without calling the provider}
                            {--approved : Re-audit already APPROVED payments for additional approved charges}';

    /**
     * @var string
     */
    protected $description = 'Ask payments-service to reconcile PENDING/UNKNOWN payments, or re-audit APPROVED payments, by querying the provider (never creates a charge or refund)';

    /**
     * S4 moved provider reconciliation to payments-service. The monolith only
     * sends the signed internal command, so provider credentials, duplicate
     * checks and the payment row live in exactly one owner.
     *
     * When the service is unavailable the command fails closed and the next
     * run retries; no payment or order state is changed locally.
     */
    public function handle(PaymentsReconciliationClient $client): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        try {
            if ((bool) $this->option('approved')) {
                $result = $client->auditApproved($limit, $dryRun);

                if ($dryRun) {
                    $this->info('Approved payments to audit: '.$result['inspected']);

                    return self::SUCCESS;
                }

                $this->info('Approved payments audited: '.$result['inspected']);
                $this->info('Multiple-approval incidents: '.$result['incidents']);

                if ($result['errors'] > 0) {
                    // Never report a failed audit as a clean result.
                    $this->error('Unconfirmed audits: '.$result['errors']);

                    return self::FAILURE;
                }

                return self::SUCCESS;
            }

            $count = $client->reconcile($limit, $dryRun);

            $this->info(($dryRun ? 'Payments to reconcile: ' : 'Payments reconciled: ').$count);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Payment reconciliation failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
