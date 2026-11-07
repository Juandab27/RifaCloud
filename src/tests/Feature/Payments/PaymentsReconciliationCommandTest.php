<?php

namespace Tests\Feature\Payments;

use App\Modules\Payments\Contracts\PaymentsReconciliationClient;
use RuntimeException;
use Tests\TestCase;

/**
 * S4 moved provider reconciliation to payments-service. These tests verify the
 * monolith command only delegates and fails closed, instead of running the old
 * local reconciliation (which would create a second owner of payment state).
 *
 * The financial rules themselves remain covered by PaymentReconciliationTest
 * and ApprovedPaymentAuditTest through PaymentService, which is retained as
 * the rollback implementation.
 */
class PaymentsReconciliationCommandTest extends TestCase
{
    public function test_reconcile_command_delegates_to_payments_service(): void
    {
        $client = new class implements PaymentsReconciliationClient
        {
            public int $calls = 0;

            public array $arguments = [];

            public function reconcile(int $limit, bool $dryRun): int
            {
                $this->calls++;
                $this->arguments = [$limit, $dryRun];

                return 4;
            }

            public function auditApproved(int $limit, bool $dryRun): array
            {
                $this->calls++;

                return ['inspected' => 2, 'incidents' => 1, 'errors' => 0];
            }
        };

        $this->app->instance(PaymentsReconciliationClient::class, $client);

        $this->artisan('payments:reconcile', ['--limit' => 7])
            ->expectsOutput('Payments reconciled: 4')
            ->assertExitCode(0);

        $this->assertSame(1, $client->calls);
        $this->assertSame([7, false], $client->arguments);
    }

    public function test_reconcile_command_dry_run_delegates(): void
    {
        $client = new class implements PaymentsReconciliationClient
        {
            public array $arguments = [];

            public function reconcile(int $limit, bool $dryRun): int
            {
                $this->arguments = [$limit, $dryRun];

                return 3;
            }

            public function auditApproved(int $limit, bool $dryRun): array
            {
                return ['inspected' => 0, 'incidents' => 0, 'errors' => 0];
            }
        };

        $this->app->instance(PaymentsReconciliationClient::class, $client);

        $this->artisan('payments:reconcile', ['--dry-run' => true])
            ->expectsOutput('Payments to reconcile: 3')
            ->assertExitCode(0);

        $this->assertSame([50, true], $client->arguments);
    }

    public function test_approved_audit_delegates_and_reports_unconfirmed_errors(): void
    {
        $client = new class implements PaymentsReconciliationClient
        {
            public function reconcile(int $limit, bool $dryRun): int
            {
                return 0;
            }

            public function auditApproved(int $limit, bool $dryRun): array
            {
                return ['inspected' => 5, 'incidents' => 2, 'errors' => 1];
            }
        };

        $this->app->instance(PaymentsReconciliationClient::class, $client);

        $this->artisan('payments:reconcile', ['--approved' => true])
            ->expectsOutput('Approved payments audited: 5')
            ->expectsOutput('Multiple-approval incidents: 2')
            ->expectsOutput('Unconfirmed audits: 1')
            ->assertExitCode(1);
    }

    public function test_reconcile_command_fails_closed_when_payments_service_is_unavailable(): void
    {
        $client = new class implements PaymentsReconciliationClient
        {
            public function reconcile(int $limit, bool $dryRun): int
            {
                throw new RuntimeException('payments-service is unreachable.');
            }

            public function auditApproved(int $limit, bool $dryRun): array
            {
                throw new RuntimeException('payments-service is unreachable.');
            }
        };

        $this->app->instance(PaymentsReconciliationClient::class, $client);

        $this->artisan('payments:reconcile')
            ->expectsOutput('Payment reconciliation failed: payments-service is unreachable.')
            ->assertExitCode(1);
    }
}
