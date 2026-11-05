<?php

namespace App\Console\Commands;

use App\Modules\Payments\Services\PaymentService;
use Illuminate\Console\Command;

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
    protected $description = 'Reconcile PENDING/UNKNOWN payments, or re-audit APPROVED payments, by querying the provider (never creates a charge or refund)';

    public function handle(PaymentService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        if ((bool) $this->option('approved')) {
            $result = $service->reconcileApproved($limit, $dryRun);

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

        $count = $service->reconcile($limit, $dryRun);

        $this->info(($dryRun ? 'Payments to reconcile: ' : 'Payments reconciled: ').$count);

        return self::SUCCESS;
    }
}
