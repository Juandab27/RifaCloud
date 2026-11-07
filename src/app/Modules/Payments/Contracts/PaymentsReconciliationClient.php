<?php

namespace App\Modules\Payments\Contracts;

/**
 * Delegates provider reconciliation to payments-service (S4).
 *
 * The monolith scheduler must not query the provider or write payment/order
 * state locally: payments-service owns payments and asks orders-service for the
 * order transitions. Implementations fail closed when the service is
 * unavailable so the next scheduled/manual run retries.
 */
interface PaymentsReconciliationClient
{
    /**
     * Re-query PENDING/UNKNOWN payments. Returns the number reconciled (or the
     * number that would be inspected in dry-run mode).
     */
    public function reconcile(int $limit, bool $dryRun): int;

    /**
     * Re-audit APPROVED payments for additional approved charges.
     *
     * @return array{inspected: int, incidents: int, errors: int}
     */
    public function auditApproved(int $limit, bool $dryRun): array;
}
